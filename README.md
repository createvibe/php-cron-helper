php-cron-helper
===============

Cron Helper For PHP Scripts // Helps With Maintaining Single Job Instances, Logging, and Sig Handlers


Usage
=====

```
<?php

require_once 'Cron.php';

// binding sig handlers?
$signalId = Cron::singleton->bindSignal(\SIGTERM, function($signo)
{
	print 'Got termination signal!';
});

// attempt to acquire a lock
if (!Cron::singleton()->lock())
{
	// lock failed (already running?)
	exit;
}

// log something
Cron::singleton()->log('Doing stuff...');

/*
	do stuff
	...
*/

// log something
Cron::singleton()->log('Finished doing stuff');

// release the lock
Cron::singleton()->unlock();
```