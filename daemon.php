<?php
error_reporting(-1);
ini_set('display_errors', true);

if (!function_exists('pcntl_fork')) {
	die("PCNTL extension required");
}
if (!function_exists('posix_setsid')) {
	die("POSIX extension required");
}

$pidfile = 'daemon.pid';
$fh = fopen($pidfile, 'c+');
if (!flock($fh, LOCK_EX | LOCK_NB)) {
	die("Could not get pidfile lock, another process is probably running");
}
switch ($pid = pcntl_fork()) {
	case -1: // fork failed
		die("Could not fork");
	break;

	case 0: // i'm the child
		echo "I'm a child!\n";
	break;

	default: // i'm the parent
		echo "I'm a parent!\n";
		fseek($fh, 0);
		ftruncate($fh, 0);
		fwrite($fh, $pid);
		fflush($fh);
		echo "Parent wrote PID\n";
		exit;
}

// still a child, parent exited
if (posix_setsid() === -1) {
	die("child setsid() failed");
}

fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);

$stdin = fopen('/dev/null', 'r');
// $stdout = fopen('/dev/null', 'w');
$stdout = fopen('log', 'a+');
// $stderr = fopen('/dev/null', 'w');
$stderr = fopen('log.err', 'a+');
// echo 'break!';

function sig($i) {
	global $fh, $pidfile;
	echo "Caught signal $i";
	// fwrite(STDERR, "ERROR $i\n");
	echo $b;
	switch ($i) {
		case SIGTERM:
			fclose($fh);
			unlink($pidfile);
		break;
	}
	exit;
}
declare(ticks=1);
pcntl_signal(SIGTERM, 'sig');
var_dump(SIG_IGN);
// $log = fopen('log', 'a+');
for ($i=0; $i < 180; $i++) { 
	echo "$i\n";
	// fwrite($log, print_r(get_defined_vars(), 1));
	sleep(1);
}
