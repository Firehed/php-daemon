<?php
error_reporting(-1);
ini_set('display_errors', true);

if (!function_exists('pcntl_fork')) {
	die("PCNTL extension required");
}
if (!function_exists('posix_setsid')) {
	die("POSIX extension required");
}

class Daemon {
	
	private $pidfile;
	private $fh;
	private $childPid;
	private $script;
	private static $instance;

	public static function run($file) {
		if (self::$instance) {
			self::crash("Singletons only, please");
		}
		self::$instance = new self($file);
	}

	private static function crash($msg) {
		// Encapsulate in case we want to throw instead
		self::failed();
		die($msg."\n");
	}

	private static function showHelp() {
		$cmd = $_SERVER['_'];
		$self = $_SERVER['PHP_SELF'];
		echo "Usage: $cmd $self {status|start|stop|restart|reload|kill}\n";
		exit(0);
	}

	private function debug($msg) {
		// echo $msg,"\n";
	}

	private function __construct($script) {
		// parse options
		$this->pidfile = 'pid';
		$this->script = $script;
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

		// fork
		$this->debug("About to fork()");
		$pid = pcntl_fork();
		$this->debug("Forked!");
		switch ($pid) {
			case -1: // fork failed
				self::crash("Could not fork");
			break;

			case 0: // i'm the child
				$this->childPid = getmypid();
				$this->debug("Child process ($this->childPid)");
			break;

			default: // i'm the parent
				$this->debug("Parent process ($pid)");
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
		fclose(STDIN);
		fclose(STDOUT);
		fclose(STDERR);
		$this->stdin  = fopen('/dev/null', 'r');
		$this->stdout = fopen('log', 'a+');
		$this->stderr = fopen('log.err', 'a+');
		$this->debug("Reopened handles");

		// var_dump($this);
		// install signal handlers
		declare(ticks=1);
		pcntl_signal(SIGTERM, array($this, 'signalhandler'));
		// echo "set sigterm handler\n";
		// print_r(debug_backtrace());
		$this->debug("Executing original script");
		include $this->script;
	}

	private function signalhandler($sig) {
		echo "Got sig $sig\n";
		// var_dump($sig);
	// 	switch ($sig) {
	// 		case SIGTERM:
	// 			fclose($this->fh);
	// 			unlink($this->pidfile);
	// 			
	// 			exit;
	// 		break;
	// 
	// 		default:
	// 		break;
	// 	}
	}

	private static function terminate($pid, $signal) {
		if (!posix_kill($pid, $signal)) {
			self::crash("Process $pid not running!");
		}
		$i = 0;
		while (posix_kill($pid, 0)) { // Wait until the child goes away
			if ($i++ >= 15) {
				self::crash("Could not terminate process");
			}
			self::show('.');
			sleep(1);
		}
		self::ok();
	}

	private function stop() {
		$pid = file_get_contents($this->pidfile);
		self::show("Stopping ");
		self::terminate($pid, SIGTERM);
	}
	function restart() {
		$this->stop();
		$this->start();
	}
	function reload() {
		// posix_kill(SIGUSR1)
		self::crash(2);
	}
	function status() {
		// posix_kill(pid,0) ensure rinning
		// "Running, PID:", "Stopped"
		self::crash(2);
	}
	function kill() {
		$pid = file_get_contents($this->pidfile);
		self::show("Sending SIGKILL ");
		self::terminate($pid, SIGKILL);
	}
	private function getChildPid() {
		
	}

	private static $chars = 0;
	static function show($text) {
		// printf("%-59s", $text);
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

Daemon::run('test.php');

// 
// echo "Starting\n";
// daemon_start();
// echo "Started\n";
// 
// 
// function sig($i) {
// 	global $fh, $pidfile;
// 	echo "Caught signal $i";
// 	// fwrite(STDERR, "ERROR $i\n");
// 	echo $b;
// 	switch ($i) {
// 		case SIGTERM:
// 			fclose($fh);
// 			unlink($pidfile);
// 		break;
// 	}
// 	exit;
// }
// // var_dump(SIG_IGN);
// // $log = fopen('log', 'a+');
