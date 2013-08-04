<?php
/**
 *	Class Cron
 *
 *	The MIT License (MIT)
 *
 *	Copyright (c) 2013 Anthony Matarazzo
 *
 *	Permission is hereby granted, free of charge, to any person obtaining a copy of
 *	this software and associated documentation files (the "Software"), to deal in
 *	the Software without restriction, including without limitation the rights to
 *	use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 *	the Software, and to permit persons to whom the Software is furnished to do so,
 *	subject to the following conditions:
 *
 *	The above copyright notice and this permission notice shall be included in all
 *	copies or substantial portions of the Software.
 *
 *	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
 *	FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 *	COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
 *	IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
 *	CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 *	@author Anthony Matarazzo <email@anthonymatarazzo.com>
 */

class Cron
{
	/**
	 * The singleton instance
	 * @var Cron|null
	 */
	private static $instance = null;

	/**
	 * The current PID
	 * @var string|int|null
	 */
	private $pid;

	/**
	 * The current file name
	 * @var string|null
	 */
	private $filename;

	/**
	 * Cron job params
	 * @var array
	 */
	private $params = array();

	/*
	 * Sig handlers (listeners)
	 * @var array<Function>
	 */
	private $sigHandlers = array();

	/**
	 * The log path
	 * @var string
	 */
	private $logPath = './logs/';

	/**
	 * The lock path
	 * @var string
	 */
	private $lockPath = './pids/';

	/**
	 * Suffix to lock files
	 * @var string
	 */
	private $lockSuffix = '.PID';

	/**
	* Magic getter for access to private vars
	* 
	* @param string $name
	* @return mixed
	*/
	public function __get( $name )
	{
		switch ( $name )
		{
			case 'pid' :
				return $this->pid;
			
			case 'filename' :
				return $this->filename;
			
			case 'params' :
				return $this->params;
		}
		return null;
	}
	
	/**
	* Constructor
	* 
	* @param mixed $pid the process id for the currently running script
	* @param array $argv arguments passed at runtime, index 0 is the filename
	*/
	private function __construct($pid, array $argv)
	{
		$this->pid = $pid;
		$this->filename = basename(array_shift($argv));
		$this->params = $argv;
	}
	
	/**
	* Protect this class from being cloned
	* @throws \Exception
	*/
	public function __clone()
	{
		throw new \Exception('You cannot clone the Cron class');
	}
	
	/**
	* Get the singleton object for this class
	* 
	* @return Cron
	*/
	public static function singleton()
	{
		if (!is_object(self::$instance))
		{
			self::$instance = new Cron(posix_getpid(), $GLOBALS['argv']);
			
			declare(ticks = 1);
			
			$sig_handler = array(self::$instance, 'sigHandler');
			pcntl_signal(SIGTERM, $sig_handler);
			pcntl_signal(SIGHUP, $sig_handler);
		}
		return self::$instance;
	}
	
	/**
	* Get a list of currently running PIDs
	* 
	* @return array of PIDs
	*/
	public static function getpids()
	{
		return explode(PHP_EOL, shell_exec("ps -e | awk '{print $1}'"));
	}
	
	/**
	* Write a log entry for this file
	* 
	* @param string $str the message to log
	*/
	public function log($str)
	{
		$lock_file = $this->logPath . $this->filename .'_'. date('Y-M-d') .'.LOG';
		file_put_contents($lock_file, '['. date('H:i:s') .'] {PID:'. $this->pid .'} '. trim($str) ."\n", FILE_APPEND);
	}
	
	/**
	* Internal signal callback for pcntl_signal
	* 
	* @param int $signo
	*/
	public function sigHandler($signo)
	{
		// internal handling
		switch ( $signo )
		{
			case SIGHUP :
			{
				// handle restart tasks
				$this->log('caught restart signal - halting script.');
				$this->unlock();
				
				// execute any bound callbacks for the given signal
				$this->execSignals(SIGHUP);
				
				exit;
			}
			case SIGTERM :
			{
				// handle shutdown tasks
				$this->log('caught termination signal - halting script.');
				$this->unlock();
				
				// execute any bound callbacks for the given signal
				$this->execSignals(SIGTERM);
				
				exit;
			}
			default :
			{
				$this->log('caught unknown signal: '. $signo);
				
				// execute any bound callbacks for the given signal
				$this->execSignals($signo);
				
				break;
			}
		}
	}
	
	/**
	* Execute assigned callbacks for a given signal
	* 
	* @param int $signo the signal in question (SIGTERM, SIGHUP, etc)
	*/
	public function execSignals($signo)
	{
		if (isset($this->sigHandlers[$signo]))
		{
			foreach ($this->sigHandlers[$signo] as $idx => $callback)
			{
				if (is_callable($callback))
				{
					$this->log('executing signal callback for signal: '. $signo .' at index: '. $idx);
					call_user_func($callback, $signo);
				}
			}
		}
	}
	
	/**
	* Bind a callback function to execute when a pnctl-signal is triggered
	* 
	* @param int $signo the signal in question (SIGTERM, SIGHUP, etc)
	* @param callback $callback the callback function to execute
	* @return int the index of the newly assigned callback
	*/
	public function bindSignal($signo, $callback)
	{
		if (!isset($this->sigHandlers[$signo]))
		{
			$this->sigHandlers[$signo] = array($callback);
			return 0;
		}
		$idx = count($this->sigHandlers[$signo]);
		$this->sigHandlers[$signo][$idx] = $callback;
		return $idx;
	}
	
	/**
	* Unbind a callback function assigned to a pnctl-signal
	* 
	* @param int $signo the signal in question (SIGTERM, SIGHUP, etc)
	* @param int $idx the index of the assigned signal callback handler you wish to remove - null for all handlers in a given signal
	* @return bool
	*/
	public function unBindSignal($signo, $idx=null)
	{
		if (!isset($this->sigHandlers[$signo]))
		{
			return true;
		}

		if ( is_null($idx) )
		{
			unset($this->sigHandlers[$signo]);
		}
		elseif ( isset($this->sigHandlers[$signo][$idx]) )
		{
			unset($this->sigHandlers[$signo][$idx]);
		}

		return true;
	}
	
	/**
	* See if a given PID is in the list of currently running PIDs
	* 
	* @param mixed $pid a PID to check for or null for the current PID
	* @return bool
	*/
	public function isRunning($pid=null)
	{
		$pid = !is_null($pid) ? $pid : $this->pid;
		return in_array($pid, $this->getpids());
	}
	
	/**
	* See if a lock already exists for this file
	* 
	* @return bool
	*/
	public function isLocked()
	{
		$lock_file = $this->lockPath . $this->filename . $this->lockSuffix;
		return file_exists($lock_file);
	}
	
	/**
	* Create a lock on this file and associate the currently running PID with i
	* 
	* @return bool
	*/
	public function lock()
	{
		$lock_file = $this->lockPath . $this->filename . $this->lockSuffix;
		if (file_exists($lock_file))
		{
			$pid = trim(file_get_contents($lock_file));
			if ( $this->isRunning($pid) )
			{
				$this->log('running as PID:'. $this->pid .' attempted to attain a lock but one was already provided for PID:'. $pid);
				return false;
			}
			else
			{
				$this->log('running as PID:'. $pid .' unexpectedly died');
			}
		}
		file_put_contents($lock_file, $this->pid);
		$this->log('acquired lock with PID:'. $this->pid);
		return true;
	}
	
	/**
	* Try to unlock any previous lock that was applied to this file
	* 
	*/
	public function unlock()
	{
		$bool = true;
		$lock_file = $this->lockPath . $this->filename . $this->lockSuffix;
		if (file_exists($lock_file))
		{
			$lock_pid = trim(file_get_contents($lock_file));
			if (!($bool = unlink($lock_file)))
			{
				$this->log('lock exists with PID:'. $lock_pid .' but it could not be released.');
			}
			else
			{
				$this->log('lock with PID:'. $lock_pid .' has been released.');
			}
		}
		else
		{
			$this->log('tried to release lock, but no lock was found.');
		}
		return $bool;
	}

	/**
	 * Set the log path
	 * @param string $path
	 * @throws \Exception
	 * @return Cron This object (fluent interface)
	 */
	public function setLogPath($path)
	{
		if (!is_dir($path))
		{
			throw new \Exception($path . ' does not exist.');
		}
		$this->logPath = $path;
		return $this;
	}

	/**
	 * Get the log path
	 * @return string
	 */
	public function getLogPath()
	{
		return $this->logPath;
	}

	/**
	 * Set the lock path
	 * @param string $path
	 * @throws \Exception
	 * @return Cron This object (fluent interface)
	 */
	public function setLockPath($path)
	{
		if (!is_dir($path))
		{
			throw new \Exception($path . ' does not exist.');
		}
		$this->lockPath = $path;
		return $this;
	}

	/**
	 * Get the lock path
	 * @return string
	 */
	public function getLockPath()
	{
		return $this->lockPath;
	}

	/**
	 * Set the lock suffix
	 * @param string $suffix
	 * @return Cron This object (fluent interface)
	 */
	public function setLockSuffix($suffix)
	{
		$this->lockSuffix = $suffix;
		return $this;
	}

	/**
	 * Get the lock suffix
	 * @return string
	 */
	public function getLockSuffix()
	{
		return $this->lockSuffix;
	}
}