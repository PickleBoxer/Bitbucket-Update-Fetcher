<?php

require(__DIR__ . '/../../vendor/autoload.php');

use BitbucketUpdateFetcher\UpdateFetcher;

$fetcher = new UpdateFetcher(__DIR__ . '/temp', __DIR__ . '/../install', 60);
$fetcher->setCurrentVersion('1.0.0');
$fetcher->setWorkspace('');
$fetcher->setRepoSlug('');
$fetcher->setAccessToken('');

// Custom logger (optional)
$logger = new \Monolog\Logger("default");
$logger->pushHandler(new Monolog\Handler\StreamHandler(__DIR__ . '/update.log'));
$fetcher->setLogger($logger);

// Cache (optional but recommended)
$cache = new Desarrolla2\Cache\File(__DIR__ . '/cache');
$fetcher->setCache($cache, 3600);

// Check for a new update
if ($fetcher->checkUpdate() === false) {
    die('Could not check for updates! See log file for details.');
}

if ($fetcher->newVersionAvailable()) {
    // Install new update
    echo 'New Version: ' . $fetcher->getLatestVersion() . '<br>';
    echo 'Installing Updates: <br>';
    echo '<pre>';
    var_dump(array_map(function ($version) {
        return (string) $version;
    }, $fetcher->getVersionsToUpdate()));
    echo '</pre>';

    // Optional - empty log file
    $f = @fopen(__DIR__ . '/update.log', 'rb+');
    if ($f !== false) {
        ftruncate($f, 0);
        fclose($f);
    }

    // Optional Callback function - on each version update
    function eachUpdateFinishCallback($updatedVersion)
    {
        echo '<h3>CALLBACK for version ' . $updatedVersion . '</h3>';
    }
    $fetcher->onEachUpdateFinish('eachUpdateFinishCallback');

    // Optional Callback function - on each version update
    function onAllUpdateFinishCallbacks($updatedVersions)
    {
        echo '<h3>CALLBACK for all updated versions:</h3>';
        echo '<ul>';
        foreach ($updatedVersions as $v) {
            echo '<li>' . $v . '</li>';
        }
        echo '</ul>';
    }
    $fetcher->setOnAllUpdateFinishCallbacks('onAllUpdateFinishCallbacks');

    // This call will only simulate an update.
    // Set the first argument (simulate) to "false" to install the update
    // i.e. $update->update(false);
    $result = $fetcher->update();

    if ($result === true) {
        echo 'Update simulation successful<br>';
    } else {
        echo 'Update simulation failed: ' . $result . '!<br>';

        if ($result == UpdateFetcher::ERROR_SIMULATE) {
            echo '<pre>';
            var_dump($fetcher->getSimulationResults());
            echo '</pre>';
        }
    }
} else {
    echo 'Current Version is up to date<br>';
}

echo 'Log:<br>';
echo nl2br(file_get_contents(__DIR__ . '/update.log'));
