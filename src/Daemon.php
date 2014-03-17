<?php

namespace Firehed\ProcessControl;

use InvalidArgumentException;

class Daemon {

	private $pidfile = 'pid';
	private $logFile = 'log';
	private $errFile = 'log.err';
	private $termLimit = 20;
	private $fh;
	private $childPid;
	private $didTick = false;
	private $userId = null;

	private static $instance;

	private static function crash($msg) {
		// Encapsulate in case we want to throw instead
		self::failed();
		die($msg."\n");
	}

	private static function showHelp() {
		$cmd = $_SERVER['PHP_SELF'];
		echo "Usage: $cmd {status|start|stop|restart|reload|kill}\n";
		exit(0);
	}

	private function debug($msg) {
		// echo $msg,"\n";
	}

	public function didTick() {
		$this->didTick = true;
	}

	private function checkForDeclareDirective() {
		register_tick_function([$this, 'didTick']);
		usleep(1);
		if (!$this->didTick) {
			// Try a bunch of no-ops in case the directive is set as > 1
			$i = 1000;
			while ($i--);
		}
		unregister_tick_function([$this, 'didTick']);
		if (!$this->didTick) {
			fwrite(STDERR, "It looks like `declare(ticks=1);` has not been ".
				"called, so signals to stop the daemon will fail. Ensure ".
				"that the root-level script calls this.\n");
			exit(1);
		}
	}

	public function __construct() {
		if (self::$instance) {
			self::crash("Singletons only, please");
		}
		self::$instance = true;

		// parse options
		$this->checkForDeclareDirective();
	}

	public function setUser($systemUsername) {
		$info = posix_getpwnam($systemUsername);
		if (!$info) {
			self::crash("User '$systemUsername' not found");
		}
		$this->userId = $info['uid'];
		return $this;
	}

	public function setProcessName($name) {
		if (function_exists('cli_set_process_title')) {
			cli_set_process_title($name);
		}
		return $this;
	}

	public function setPidFileLocation($path) {
		if (!is_string($path)) {
			throw new InvalidArgumentException("Pidfile path must be a string");
		}
		$this->pidfile = $path;
		return $this;
	}

	public function setStdoutFileLocation($path) {
		if (!is_string($path)) {
			throw new InvalidArgumentException("Stdout path must be a string");
		}
		$this->logFile = $path;
		return $this;
	}

	public function setStderrFileLocation($path) {
		if (!is_string($path)) {
			throw new InvalidArgumentException("Stderr path must be a string");
		}
		$this->errFile = $path;
		return $this;
	}

	public function setTerminateLimit($seconds) {
		if (!is_int($seconds) || $seconds < 1) {
			throw new InvalidArgumentException("Limit must be a positive int");
		}
		$this->termLimit = $seconds;
		return $this;
	}

	public function autoRun() {
		if ($_SERVER['argc'] < 2) {
			self::showHelp();
		}
		$cmd = strtolower(end($_SERVER['argv']));
		switch ($cmd) {
			case 'start':
			case 'stop':
			case 'restart':
			case 'reload':
			case 'status':
			case 'kill':
				call_user_func(array($this, $cmd));
			break;
			default:
				self::showHelp();
			break;
		}
	}

	private function start() {
		self::show("Starting...");
		// Open and lock PID file
		$this->fh = fopen($this->pidfile, 'c+');
		if (!flock($this->fh, LOCK_EX | LOCK_NB)) {
			self::crash("Could not lock the pidfile. This daemon may already ".
			   "be running.");
		}

		// Fork
		$this->debug("About to fork");
		$pid = pcntl_fork();
		switch ($pid) {
			case -1: // fork failed
				self::crash("Could not fork");
			break;

			case 0: // i'm the child
				$this->childPid = getmypid();
				$this->debug("Forked - child process ($this->childPid)");
			break;

			default: // i'm the parent
				$me = getmypid();
				$this->debug("Forked - parent process ($me -> $pid)");
				fseek($this->fh, 0);
				ftruncate($this->fh, 0);
				fwrite($this->fh, $pid);
				fflush($this->fh);
				$this->debug("Parent wrote PID");
				exit;
		}

		// detatch from terminal
		if (posix_setsid() === -1) {
			self::crash("Child process could not detach from terminal.");
		}
		if (null !== $this->userId) {
			if (!posix_setuid($this->userId)) {
				self::crash("Could not change user. Try running this program".
					" as root.");
			}
		}

		self::ok();
		// stdin/etc reset
		$this->debug("Resetting file descriptors");
		fclose(STDIN);
		fclose(STDOUT);
		fclose(STDERR);
		$this->stdin  = fopen('/dev/null', 'r');
		$this->stdout = fopen($this->logFile, 'a+');
		$this->stderr = fopen($this->errFile, 'a+');
		$this->debug("Reopened file descriptors");
		$this->debug("Executing original script");
		pcntl_signal(SIGTERM, function() { exit; });
	}

	private function terminate($msg, $signal) {
		self::show($msg);
		$pid = $this->getChildPid();
		if (false === $pid) {
			self::failed();
			echo "No PID file found\n";
			return;
		}
		if (!posix_kill($pid, $signal)) {
			self::failed();
			echo "Process $pid not running!\n";
			return;
		}
		$i = 0;
		while (posix_kill($pid, 0)) { // Wait until the child goes away
			if (++$i >= $this->termLimit) {
				self::crash("Process $pid did not terminate after $i seconds");
			}
			self::show('.');
			sleep(1);
		}
		self::ok();
	}

	public function __destruct() {
		if (getmypid() == $this->childPid) {
			unlink($this->pidfile);
		}
	}

	private function stop($exit = true) {
		$this->terminate('Stopping', SIGTERM);
		$exit && exit;
	}
	private function restart() {
		$this->stop(false);
		$this->start();
	}

	private function reload() {
		$pid = $this->getChildPid();
		self::show("Sending SIGUSR1");
		if ($pid && posix_kill($pid, SIGUSR1)) {
			self::ok();
		}
		else {
			self::failed();
		}
		exit;
	}

	private function status() {
		$pid = $this->getChildPid();
		if (!$pid) {
			echo "Process is stopped\n";
			exit(3);
		}
		if (posix_kill($pid, 0)) {
			echo "Process (pid $pid) is running...\n";
			exit(0);
		}
		// # See if /var/lock/subsys/${base} exists
		// if [ -f /var/lock/subsys/${base} ]; then
		// 	echo $"${base} dead but subsys locked"
		// 	return 2
		// fi¬
		else {
			echo "Process dead but pid file exists\n";
			exit(1);
		}
	}

	private function kill() {
		$this->terminate('Sending SIGKILL', SIGKILL);
		exit;
	}

	private function getChildPid() {
		return file_exists($this->pidfile)
			? file_get_contents($this->pidfile)
			: false;
	}

	// make output pretty
	private static $chars = 0;
	private static function show($text) {
		echo $text;
		self::$chars += strlen($text);
	}

	private static function ok() {
		echo str_repeat(' ', 59-self::$chars);
		echo "[\033[0;32m  OK  \033[0m]\n";
		self::$chars = 0;
	}

	private static function failed() {
		echo str_repeat(' ', 59-self::$chars);
		echo "[\033[0;31mFAILED\033[0m]\n";
		self::$chars = 0;
	}

}
