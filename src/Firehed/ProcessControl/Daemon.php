<?php
/**
 * php-daemon
 * 
 * PHP Version <=5.3.3
 *
 * @category Utility
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 * @link https://github.com/Firehed/php-daemon php-daemon
 */
namespace Firehed\ProcessControl;

/**
 * Bootstrapping class for handeling demonization of php process
 *
 * @category Utility
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 * @link https://github.com/Firehed/php-daemon php-daemon
 */
class Daemon
{

    /**
     * Path to the process identification number file (pid)
     *
     * @var string
     */
    private $pidfile;

    /**
     * File handle
     *
     * @var stream
     */
    private $fh;

    /**
     * Child process id
     *
     * @var integer
     */
    private $childPid;

    /**
     * Instance of the daemon
     *
     * @var Firehed\ProcessControl\Daemon
     */
    private static $instance;

    /**
     * Make output pretty
     *
     * @var integer
     */
    private static $chars = 0;

    /**
     * Run the Daemon
     *
     * @return void
     */
    public static function run()
    {
        if (self::$instance) {
            self::crash("Singletons only, please");
        }
        self::$instance = new self();
    }

    /**
     * Chrash the daemon
     *
     * @param string $msg            
     *
     * @return void
     */
    private static function crash($msg)
    {
        // Encapsulate in case we want to throw instead
        self::failed();
        die($msg . "\n");
    }

    /**
     * Show help
     *
     * @return void
     */
    private static function showHelp()
    {
        $cmd = $_SERVER['_'];
        if ($cmd != $_SERVER['PHP_SELF']) {
            $cmd .= ' ' . $_SERVER['PHP_SELF'];
        }
        echo "\033[0;35mUsage:\033[0m " . $cmd . " " . self::listCommands() . "\n";
        exit(0);
    }

    /**
     * List possible commands
     *
     * @return string
     */
    private static function listCommands()
    {
        $commands = array(
            'status',
            'start',
            'stop',
            'restart',
            'reload',
            'kill'
        );
        $list = array();
        foreach ($commands as $command) {
            $list[] = "\033[0;36m" . $command . "\033[0m";
        }
        return "{" . substr(implode('|', $list), 0, - 1) . "\033[0;32m}";
    }

    /**
     * Print debug message
     *
     * @param string $msg            
     * @return void
     */
    private function debug($msg)
    {
        // echo $msg,"\n";
    }

    /**
     * Construct a new deamon handler
     *
     * @return void
     */
    private function __construct()
    {
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
                call_user_func(array(
                    $this,
                    $_SERVER['argv'][1]
                ));
                break;
            default:
                self::showHelp();
                break;
        }
    }

    /**
     * Start the daemon
     *
     * @return void
     */
    private function start()
    {
        self::show("Starting...");
        // Open and lock PID file
        $this->fh = fopen($this->pidfile, 'c+');
        if (! flock($this->fh, LOCK_EX | LOCK_NB)) {
            self::crash("Could not get pidfile lock, another process is probably running");
        }
        
        // Fork
        $this->debug("About to fork");
        $pid = pcntl_fork();
        switch ($pid) {
            case - 1: // fork failed
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
                exit();
        }
        
        // detatch from terminal
        if (posix_setsid() === - 1) {
            self::crash("Child process setsid() call failed, could not detach from terminal");
        }
        
        self::ok();
        // stdin/etc reset
        $this->debug("Resetting file descriptors");
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $this->stdin = fopen('/dev/null', 'r');
        $this->stdout = fopen('log', 'a+');
        $this->stderr = fopen('log.err', 'a+');
        $this->debug("Reopened file descriptors");
        $this->debug("Executing original script");
        pcntl_signal(SIGTERM, function ()
        {
            exit();
        });
    }

    /**
     * Terminate the daemon
     *
     * @return void
     */
    private function terminate($msg, $signal)
    {
        self::show($msg);
        $pid = $this->getChildPid();
        if (false === $pid) {
            self::failed();
            echo "No PID file found\n";
            return;
        }
        if (! posix_kill($pid, $signal)) {
            self::failed();
            echo "Process $pid not running!\n";
            return;
        }
        $i = 0;
        while (posix_kill($pid, 0)) { // Wait until the child goes away
            if (++ $i >= 20) {
                self::crash("Process $pid did not terminate after $i seconds");
            }
            self::show('.');
            sleep(1);
        }
        self::ok();
    }

    /**
     * Cleanup
     *
     * @return void
     */
    private function __destruct()
    {
        if (getmypid() == $this->childPid) {
            unlink($this->pidfile);
        }
    }

    /**
     * Stop the daemon
     *
     * @return void
     */
    private function stop($exit = true)
    {
        $this->terminate('Stopping', SIGTERM);
        $exit && exit();
    }

    /**
     * Restart the daemon
     *
     * @return void
     */
    private function restart()
    {
        $this->stop(false);
        $this->start();
    }

    /**
     * Reload the daemon
     *
     * @return void
     */
    private function reload()
    {
        $pid = $this->getChildPid();
        self::show("Sending SIGUSR1");
        if ($pid && posix_kill($pid, SIGUSR1)) {
            self::ok();
        } else {
            self::failed();
        }
        exit();
    }

    /**
     * Get status for the daemon
     *
     * @return void
     */
    private function status()
    {
        $pid = $this->getChildPid();
        if (! $pid) {
            echo "Process is stopped\n";
            exit(3);
        }
        if (posix_kill($pid, 0)) {
            echo "Process (pid $pid) is running...\n";
            exit(0);
        }         // # See if /var/lock/subsys/${base} exists
          // if [ -f /var/lock/subsys/${base} ]; then
          // echo $"${base} dead but subsys locked"
          // return 2
          // fi¬
        else {
            echo "Process dead but pid file exists\n";
            exit(1);
        }
    }

    /**
     * Kill the daemon
     *
     * @return void
     */
    public function kill()
    {
        $this->terminate('Sending SIGKILL', SIGKILL);
        exit();
    }

    /**
     * Get the child process id
     *
     * @return integer boolean
     */
    private function getChildPid()
    {
        return file_exists($this->pidfile) ? file_get_contents($this->pidfile) : false;
    }

    /**
     * Print some text to the screen
     *
     * @param string $text            
     *
     * @return void
     */
    public static function show($text)
    {
        echo $text;
        self::$chars += strlen($text);
    }

    /**
     * Print ok string to the screen
     *
     * @return void
     */
    public static function ok()
    {
        echo str_repeat(' ', 59 - self::$chars);
        echo "[\033[0;32m  OK  \033[0m]\n";
        self::$chars = 0;
    }

    /**
     * Print failed string to the screen
     *
     * @return void
     */
    public static function failed()
    {
        echo str_repeat(' ', 59 - self::$chars);
        echo "[\033[0;31mFAILED\033[0m]\n";
        self::$chars = 0;
    }
}
