<?php

$destFile = $argv[1] ?? "/usr/local/bin/sender";

$pharFile = $destFile . ".phar";

// Clean up
if (file_exists($pharFile)) {
    unlink($pharFile);
}

// Create a new Phar archive
try {
        $phar = new Phar($pharFile);
        $phar->startBuffering();
        
        // Add files to the Phar archive
        #$phar->buildFromDirectory(__DIR__);
        $phar->addFile(__DIR__ .'/setup.php', 'setup.php');
        $phar->addFile(__DIR__ .'/generate.php', 'generate.php');
        $phar->addFile(__DIR__ .'/receiver.php', 'receiver.php');
        $phar->addFile(__DIR__ .'/sender.php', 'sender.php');
        $phar->addFile(__DIR__ .'/sender', 'sender');
        
        // Set the stub (entry point) for the Phar archive
        $stub = "#!/usr/bin/env php\n" . Phar::createDefaultStub('sender');

        $phar->setStub($stub);
        $phar->stopBuffering();
}
catch (Exception $e) {
    echo 'Could not create Phar: ', $e->getMessage();
}

chmod($pharFile, 0755);
rename($pharFile, $destFile);
