# Daemon
A really useful tool with a very boring name. Daemonize your PHP scripts with two lines of code.

## Requirements
* `posix` and `pcntl` extensions
* PHP5 (Written and tested in 5.3, but any version >=5.0.0 should work)
* Basic knowledge of PHP on the command line

## Usage
Code:

	<?php
	// Preferred:
	require 'vendor/autoload.php'; // composer
	// Alternately:
	// include 'path/to/daemon.php';
	Firehed\ProcessControl\Daemon::run();
	// The rest of your original script

CLI:

	php yourscript.php {status|start|stop|restart|reload|kill}

Yes, it's that simple.

### Actions
* Status: Check the status of the process. Returns:
	* 0 if running
	* 1 if dead but pidfile is hanging around
	* 3 if stopped
* Start: Start the daemon
* Stop: Stop the daemon gracefully via SIGTERM
* Restart: Stop (if running) and start
* Reload: Send SIGUSR1 to daemon (you need to implement a reload function, see below)
* Kill: Kill the daemon via SIGKILL (kill -9)

## Options
None yet. I intend to add configuration for:

* Verbose output
* Synchronous mode (do not daemonize for debugging)
* Log file configuration

## Useful tips

* STDOUT (echo, print) is redirected to the log file.
* The "reload" command won't do anything without installing a handler for SIGUSR1. Examples are due shortly.


## Known Issues

* STDERR doesn't appear to go anywhere, despite opening a logfile for it.
* The script can't set up "reload" bindings automatically. This is a PHP limitation: "The declare construct can also be used in the global scope, affecting all code following it (**however if the file with declare was included then it does not affect the parent file**)". [http://docs.php.net/manual/en/control-structures.declare.php]()