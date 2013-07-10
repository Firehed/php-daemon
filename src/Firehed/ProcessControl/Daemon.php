<?php

namespace Firehed\ProcessControl;

class Daemon {

	private $pidfile;
	private $fh;
	private $childPid;
	private static $instance;

	public static function run() {
		if (self::$instance) {
			self::crash("Singletons only, please");
		}
		self::$instance = new self;
	}

	private static function crash($msg) {
		// Encapsulate in case we want to throw instead
		self::failed();
		die($msg."\n");
	}

	private static function showHelp() {
		$cmd = $_SERVER['_'];
		if ($cmd != $_SERVER['PHP_SELF']) {
			$cmd .= ' ' . $_SERVER['PHP_SELF'];
		}
		echo "Usage: $cmd {status|start|stop|restart|reload|kill}\n";
		exit(0);
	}

	private function debug($msg) {
		// echo $msg,"\n";
	}

	private function __construct() {
		if (!function_exists('pcntl_fork')) {
			die("PCNTL extension required\n");
		}
		if (!function_exists('posix_setsid')) {
			die("POSIX extension required\n");
		}

		// parse options
		$this->pidfile = 'pid';
		if ($_SERVER['argc'] < 2) {
			self::showHelp();
		}
		switch (strtolower($_SERVER['argv'][1])) {
			case 'start':
			case 'stop':
			case 'restart':
			case 'reload':
			case 'status':
			case 'kill':
				call_user_func(array($this, $_SERVER['argv'][1]));
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
			self::crash("Could not get pidfile lock, another process is probably running");
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
			self::crash("Child process setsid() call failed, could not detach from terminal");
		}

		self::ok();
		// stdin/etc reset
		$this->debug("Resetting file descriptors");
		fclose(STDIN);
		fclose(STDOUT);
		fclose(STDERR);
		$this->stdin  = fopen('/dev/null', 'r');
		$this->stdout = fopen('log', 'a+');
		$this->stderr = fopen('log.err', 'a+');
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
			if (++$i >= 20) {
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
	function kill() {
		$this->terminate('Sending SIGKILL', SIGKILL);
		exit;
	}
	private function getChildPid() {
		return file_exists($this->pidfile) ? file_get_contents($this->pidfile) : false;
	}

	// make output pretty
	private static $chars = 0;
	static function show($text) {
		echo $text;
		self::$chars += strlen($text);
	}
	static function ok() {
		echo str_repeat(' ', 59-self::$chars);
		echo "[\033[0;32m  OK  \033[0m]\n";
		self::$chars = 0;
	}
	static function failed() {
		echo str_repeat(' ', 59-self::$chars);
		echo "[\033[0;31mFAILED\033[0m]\n";
		self::$chars = 0;
	}

}
