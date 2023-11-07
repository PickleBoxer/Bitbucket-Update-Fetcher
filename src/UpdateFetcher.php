<?php

namespace BitbucketUpdateFetcher;

use Exception;
use RuntimeException;
use ZipArchive;

use Composer\Semver\Comparator;
use Desarrolla2\Cache\CacheInterface;
use Desarrolla2\Cache\NotCache;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\InvalidArgumentException;
use BitbucketUpdateFetcher\Exceptions\DownloadException;
use BitbucketUpdateFetcher\Exceptions\ParserException;

class UpdateFetcher
{
    /**
     * The latest version.
     *
     * @var string
     */
    private $latestVersion;

    /**
     * Updates not yet installed.
     *
     * @var array
     */
    private $updates;

    /**
     * Cache for update requests.
     *
     * @var CacheInterface
     */
    private $cache;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private $log;

    /**
     * Result of simulated installation.
     *
     * @var array
     */
    private $simulationResults = array();

    /**
     * Temporary download directory.
     *
     * @var string
     */
    private $tempDir = '';

    /**
     * Install directory.
     *
     * @var string
     */
    private $installDir = '';

    /**
     * Update branch.
     *
     * @var string
     */
    private $branch = '';

    /*
     * Callbacks to be called when each update is finished
     *
     * @var array
     */
    private $onEachUpdateFinishCallbacks = [];

    /*
     * Callbacks to be called when all updates are finished
     *
     * @var array
     */
    private $onAllUpdateFinishCallbacks = [];

    /**
     * If curl should verify the host certificate.
     *
     * @var bool
     */
    private $sslVerifyHost = true;

    /**
     * Url to the bitbucket api.
     *
     * @var string
     */
    protected $updateUrl = 'https://api.bitbucket.org/2.0/repositories/{workspace}/{repo_slug}/downloads';

    /**
     * The name of the workspace.
     *
     * @var string
     */
    protected $workspace = '';

    /**
     * The name of the repository.
     *
     * @var string
     */
    protected $repo_slug = '';

    /**
     * Access token authentication
     *
     * @var string
     */
    private $accessToken = '';

    /**
     * Current version.
     *
     * @var string
     */
    protected $currentVersion;

    /**
     * Create new folders with these privileges.
     *
     * @var int
     */
    public $dirPermissions = 0755;

    /**
     * Update script filename.
     *
     * @var string
     */
    public $updateScriptName = '_upgrade.php';

    /**
     * How long the cache should be valid (in seconds).
     *
     * @var int
     */
    protected $cacheTtl = 3600;

    /**
     * No update available.
     */
    public const NO_UPDATE_AVAILABLE = 0;

    /**
     * Could not check for last version.
     */
    public const ERROR_VERSION_CHECK = 20;

    /**
     * Temp directory does not exist or is not writable.
     */
    public const ERROR_TEMP_DIR = 30;

    /**
     * Install directory does not exist or is not writable.
     */
    public const ERROR_INSTALL_DIR = 35;

    /**
     * Could not download update.
     */
    public const ERROR_DOWNLOAD_UPDATE = 40;

    /**
     * Could not delete zip update file.
     */
    public const ERROR_DELETE_TEMP_UPDATE = 50;

    /**
     * Error in simulated installation.
     */
    public const ERROR_SIMULATE = 70;

    /**
     * Create new instance
     *
     * @param string|null $tempDir
     * @param string|null $installDir
     */
    public function __construct(?string $tempDir = null, ?string $installDir = null, int $maxExecutionTime = 60)
    {
        /// Init logger
        $this->log = new Logger('update-fetcher');

        $this->setTempDir($tempDir ?? (__DIR__ . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR));
        //$this->setInstallDir($installDir ?? (__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR));
        $this->setInstallDir($installDir ?? (__DIR__ . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR));

        $this->latestVersion  = '0.0.0';
        $this->currentVersion = '0.0.0';

        // Init cache
        $this->cache = new NotCache();

        ini_set('max_execution_time', $maxExecutionTime);
    }

    /**
     * Set the temporary download directory.
     *
     * @param string $dir
     * @return bool
     */
    public function setTempDir(string $dir): bool
    {
        $dir = $this->addTrailingSlash($dir);

        if (!is_dir($dir)) {
            $this->log->debug(sprintf('Creating new temporary directory "%s"', $dir));

            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->log->critical(sprintf('Could not create temporary directory "%s"', $dir));

                return false;
            }
        }

        $this->tempDir = $dir;

        return true;
    }

    /**
     * Set the installation directory.
     *
     * @param string $dir
     * @return bool
     */
    public function setInstallDir(string $dir): bool
    {
        $dir = $this->addTrailingSlash($dir);

        if (!is_dir($dir)) {
            $this->log->debug(sprintf('Creating new install directory "%s"', $dir));

            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->log->critical(sprintf('Could not create install directory "%s"', $dir));

                return false;
            }
        }

        $this->installDir = $dir;

        return true;
    }

    /**
     * Set the update branch.
     *
     * @param string branch
     * @return UpdateFetcher
     */
    public function setBranch($branch): UpdateFetcher
    {
        $this->branch = $branch;

        return $this;
    }

    /**
     * Set the cache component.
     *
     * @param CacheInterface $adapter See https://github.com/desarrolla2/Cache
     * @param int $ttl
     * @return UpdateFetcher
     */
    public function setCache(CacheInterface $adapter, int $ttl): UpdateFetcher
    {
        $this->cache    = $adapter;
        $this->cacheTtl = $ttl;

        return $this;
    }

    /**
     * Set the version of the current installation.
     *
     * @param string $currentVersion
     * @return UpdateFetcher
     */
    public function setCurrentVersion(string $currentVersion): UpdateFetcher
    {
        $this->currentVersion = $currentVersion;

        return $this;
    }

    /**
     * Set the url to the bitbucket api.
     *
     * @param string $updateUrl
     * @return UpdateFetcher
     */
    public function setUpdateUrl(string $updateUrl): UpdateFetcher
    {
        $this->updateUrl = $updateUrl;

        return $this;
    }

    /**
     * Set the workspace name.
     *
     * @param string $workspace
     * @return UpdateFetcher
     */
    public function setWorkspace(string $workspace): UpdateFetcher
    {
        $this->workspace = $workspace;

        return $this;
    }

    /**
     * Set the repository name.
     *
     * @param string $repo_slug
     * @return UpdateFetcher
     */
    public function setRepoSlug(string $repo_slug): UpdateFetcher
    {
        $this->repo_slug = $repo_slug;

        return $this;
    }

    /**
     * Set the access token for authentication.
     *
     * @param string $accessToken
     * @return UpdateFetcher
     */
    public function setAccessToken(string $accessToken): UpdateFetcher
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * Replace the logger internally used by the given logger instance.
     *
     * @param LoggerInterface $logger
     * @return UpdateFetcher
     */
    public function setLogger(LoggerInterface $logger): UpdateFetcher
    {
        $this->log = $logger;

        return $this;
    }

    /**
     * Get the latest version.
     *
     * @return string
     */
    public function getLatestVersion(): string
    {
        return $this->latestVersion;
    }

    /**
     * Get an array of versions which will be installed.
     *
     * @return array
     */
    public function getVersionsToUpdate(): array
    {
        if (count($this->updates) > 0) {
            return array_map(static function ($update) {
                return $update['version'];
            }, $this->updates);
        }

        return [];
    }

    /**
     * Get the results of the last simulation.
     *
     * @return array
     */
    public function getSimulationResults(): array
    {
        return $this->simulationResults;
    }

    /**
     * @return bool
     */
    public function getSslVerifyHost(): bool
    {
        return $this->sslVerifyHost;
    }

    /**
     * @param bool $sslVerifyHost
     * @return UpdateFetcher
     */
    public function setSslVerifyHost(bool $sslVerifyHost): UpdateFetcher
    {
        $this->sslVerifyHost = $sslVerifyHost;

        return $this;
    }

    /**
     * Build the update request URL.
     *
     * @return string
     */
    private function buildUpdateRequestUrl(): string
    {
        $updateRequest = $this->updateUrl;
        $workspace = $this->workspace;
        $repo_slug = $this->repo_slug;

        $updateRequest = str_replace("{workspace}", $workspace, $updateRequest);
        $updateRequest = str_replace("{repo_slug}", $repo_slug, $updateRequest);

        return $updateRequest;
    }

    /**
     * Check for a new version
     *
     * @param int $timeout Download timeout in seconds (Only applied for downloads via curl)
     * @return int|bool
     *         true: New version is available
     *         false: Error while checking for update
     *         int: Status code (i.e. AutoUpdate::NO_UPDATE_AVAILABLE)
     * @throws DownloadException
     * @throws InvalidArgumentException
     * @throws ParserException
     */
    public function checkUpdate(int $timeout = 10)
    {
        $this->log->notice('Checking for a new update...');

        // Reset previous updates
        $this->latestVersion = '0.0.0';
        $this->updates       = [];

        $versions = $this->cache->get('update-versions');

        // Create absolute
        $updateRequest = $this->buildUpdateRequestUrl();
        if (!empty($this->branch)) {
            $updateRequest .= '.' . $this->branch;
        }

        // Check if cache is empty
        if ($versions === null || $versions === false) {
            $this->log->debug(sprintf('Get new updates from %s', $updateRequest));

            // Read list of download links associated with the repository
            if (function_exists('curl_version') && $this->isValidUrl($updateRequest)) {
                $update = $this->requestCurl($updateRequest, $timeout);

                if ($update === false) {
                    $this->log->error(sprintf('Could not get list of download links associated with the repository "%s" via curl!', $updateRequest));

                    throw new DownloadException($updateRequest);
                }
            } else {
                throw new RuntimeException('No valid rewquest method found!');
            }

            // Parse request
            $downloads = (array) json_decode($update, true);
            if (!is_array($downloads)) {
                $this->log->error('Unable to parse json update file!');

                throw new ParserException(sprintf('Could not parse request json file %s!', $updateRequest));
            }

            $versions = [];
            foreach ($downloads['values'] as $value) {
                // regular expression that matches any sequence of digits followed by a dot and then followed by any sequence of digits (which is the common format of semantic versions).
                preg_match('/(\d+\.\d+)/', $value['name'], $matches);
                $version = $matches[0] ?? $value['name'];
                $versions[$version] = $value['links']['self']['href'];
            }

            $this->cache->set('update-versions', $versions, $this->cacheTtl);
        } else {
            $this->log->debug('Got updates from cache');
        }

        if (!is_array($versions)) {
            $this->log->error(sprintf('Could not read versions from server %s', $updateRequest));

            return false;
        }

        // Check for latest version
        foreach ($versions as $version => $updateUrl) {
            if (Comparator::greaterThan($version, $this->currentVersion)) {
                if (Comparator::greaterThan($version, $this->latestVersion)) {
                    $this->latestVersion = $version;
                }

                $this->updates[] = [
                    'version' => $version,
                    'url'     => $updateUrl,
                ];
            }
        }

        // Sort versions to install
        usort($this->updates, static function ($a, $b) {
            if (Comparator::equalTo($a['version'], $b['version'])) {
                return 0;
            }

            return Comparator::lessThan($a['version'], $b['version']) ? -1 : 1;
        });

        if ($this->newVersionAvailable()) {
            $this->log->debug(sprintf('New version "%s" available', $this->latestVersion));

            return true;
        }

        $this->log->debug('No new version available');

        return self::NO_UPDATE_AVAILABLE;
    }

    /**
     * Check if a new version is available.
     *
     * @return bool
     */
    public function newVersionAvailable(): bool
    {
        return Comparator::greaterThan($this->latestVersion, $this->currentVersion);
    }

    /**
     * Check if url is valid.
     *
     * @param string $url
     * @return bool
     */
    protected function isValidUrl(string $url): bool
    {
        return (filter_var($url, FILTER_VALIDATE_URL) !== false);
    }

    /**
     * Request via curl.
     *
     * @param string $url URL 
     * @param int $timeout
     * @return string|false
     */
    protected function requestCurl(string $url, int $timeout = 10)
    {
        $authorization = "Authorization: Bearer " . $this->accessToken;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $this->sslVerifyHost ? 2 : 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->sslVerifyHost);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        // Set authentication header if access token is set.
        if ($this->accessToken !== '') {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array($authorization));
        }

        $update = curl_exec($curl);
        $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

        $this->log->debug(sprintf('Response content type: "%s"', $contentType));

        $success = true;
        if (curl_error($curl)) {
            $success = false;
            $this->log->error(sprintf(
                'Could not download update "%s" via curl: %s!',
                $url,
                curl_error($curl)
            ));
        }
        curl_close($curl);

        // If the request was successful, decode the JSON response if the content type is JSON
        if ($success === true && strpos($contentType, 'application/json') !== false) {
            $response = json_decode($update, true);

            // If the response contains an error, log it and return false
            if (isset($response['type']) && $response['type'] === 'error') {
                $success = false;
                $this->log->error(sprintf(
                    'Error in update "%s": %s',
                    $url,
                    $response['error']['message']
                ));
            }
        }

        return ($success === true) ? $update : false;
    }

    /**
     * Download the update
     *
     * @param string $updateUrl Url where to download from
     * @param string $updateFile Path where to save the download
     * @return bool
     * @throws DownloadException
     * @throws Exception
     */
    protected function downloadUpdate(string $updateUrl, string $updateFile): bool
    {
        $this->log->info(sprintf('Downloading update "%s" to "%s"', $updateUrl, $updateFile));
        if (function_exists('curl_version') && $this->isValidUrl($updateUrl)) {
            $update = $this->requestCurl($updateUrl);
            if ($update === false || empty($update)) {
                $this->log->error(sprintf('Failed to download update from "%s"!', $updateUrl));
                return false;
            }
        } else {
            throw new RuntimeException('No valid download method found!');
        }

        $handle = fopen($updateFile, 'wb');
        if (!$handle) {
            $this->log->error(sprintf('Could not open file handle to save update to "%s"!', $updateFile));

            return false;
        }

        if (!fwrite($handle, $update)) {
            $this->log->error(sprintf('Could not write update to file "%s"!', $updateFile));
            fclose($handle);

            return false;
        }

        fclose($handle);

        return true;
    }

    /**
     * Simulate update process.
     *
     * @param string $updateFile
     * @return bool
     */
    protected function simulateInstall(string $updateFile): bool
    {
        $this->log->notice('[SIMULATE] Install new version');
        clearstatcache();

        // Check if zip file could be opened
        $zip = new ZipArchive();
        $resource = $zip->open($updateFile);
        if ($resource !== true) {
            $this->log->error(sprintf('Could not open zip file "%s", error: %d', $updateFile, $resource));

            return false;
        }

        $files           = [];
        $simulateSuccess = true;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileStats        = $zip->statIndex($i);
            $filename         = $fileStats['name'];
            $foldername       = $this->installDir . dirname($filename);
            $absoluteFilename = $this->installDir . $filename;

            $files[$i] = [
                'filename'          => $filename,
                'foldername'        => $foldername,
                'absolute_filename' => $absoluteFilename,
            ];

            $this->log->debug(sprintf('[SIMULATE] Updating file "%s"', $filename));

            // Check if parent directory is writable
            if (!is_dir($foldername)) {
                if (!mkdir($foldername) && !is_dir($foldername)) {
                    throw new RuntimeException(sprintf('Directory "%s" was not created', $foldername));
                }
                $this->log->debug(sprintf('[SIMULATE] Create directory "%s"', $foldername));
                $files[$i]['parent_folder_exists'] = false;

                $parent = dirname($foldername);
                if (!is_writable($parent)) {
                    $files[$i]['parent_folder_writable'] = false;

                    $simulateSuccess = false;
                    $this->log->warning(sprintf('[SIMULATE] Directory "%s" has to be writeable!', $parent));
                } else {
                    $files[$i]['parent_folder_writable'] = true;
                }
            }

            // Skip if entry is a directory
            if ($filename[strlen($filename) - 1] === DIRECTORY_SEPARATOR) {
                continue;
            }

            // Write to file
            if (file_exists($absoluteFilename)) {
                $files[$i]['file_exists'] = true;
                if (!is_writable($absoluteFilename)) {
                    $files[$i]['file_writable'] = false;

                    $simulateSuccess = false;
                    $this->log->warning(sprintf('[SIMULATE] Could not overwrite "%s"!', $absoluteFilename));
                }
            } else {
                $files[$i]['file_exists'] = false;

                if (is_dir($foldername)) {
                    if (!is_writable($foldername)) {
                        $files[$i]['file_writable'] = false;

                        $simulateSuccess = false;
                        $this->log->warning(sprintf(
                            '[SIMULATE] The file "%s" could not be created!',
                            $absoluteFilename
                        ));
                    } else {
                        $files[$i]['file_writable'] = true;
                    }
                } else {
                    $files[$i]['file_writable'] = true;

                    $this->log->debug(sprintf('[SIMULATE] The file "%s" could be created', $absoluteFilename));
                }
            }

            if ($filename === $this->updateScriptName) {
                $this->log->debug(sprintf('[SIMULATE] Update script "%s" found', $absoluteFilename));
                $files[$i]['update_script'] = true;
            } else {
                $files[$i]['update_script'] = false;
            }
        }

        $zip->close();

        $this->simulationResults = $files;

        return $simulateSuccess;
    }

    /**
     * Install update.
     *
     * @param string $updateFile Path to the update file
     * @param bool $simulateInstall Check for directory and file permissions instead of installing the update
     * @param string $version
     * @return bool
     */
    protected function install(string $updateFile, bool $simulateInstall, string $version): bool
    {
        $this->log->notice(sprintf('Trying to install update "%s"', $updateFile));

        // Check if install should be simulated
        if ($simulateInstall) {
            if ($this->simulateInstall($updateFile)) {
                $this->log->notice(sprintf('Simulation of update "%s" process succeeded', $version));

                return true;
            }

            $this->log->critical(sprintf('Simulation of update  "%s" process failed!', $version));

            return self::ERROR_SIMULATE;
        }

        clearstatcache();

        // Install only if simulateInstall === false

        // Check if zip file could be opened
        $zip = new ZipArchive();
        $resource = $zip->open($updateFile);
        if ($resource !== true) {
            $this->log->error(sprintf('Could not open zip file "%s", error: %d', $updateFile, $resource));

            return false;
        }

        // Read every file from archive
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileStats        = $zip->statIndex($i);
            $filename         = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $fileStats['name']);
            $foldername       = str_replace(
                array('/', '\\'),
                DIRECTORY_SEPARATOR,
                $this->installDir . dirname($filename)
            );
            $absoluteFilename = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $this->installDir . $filename);
            $this->log->debug(sprintf('Updating file "%s"', $filename));

            if (!is_dir($foldername) && !mkdir($foldername, $this->dirPermissions, true) && !is_dir($foldername)) {
                $this->log->error(sprintf('Directory "%s" has to be writeable!', $foldername));

                return false;
            }

            // Skip if entry is a directory
            if ($filename[strlen($filename) - 1] === DIRECTORY_SEPARATOR) {
                continue;
            }

            // Extract file
            if ($zip->extractTo($this->installDir, $fileStats['name']) === false) {
                $this->log->error(sprintf('Could not read zip entry "%s"', $fileStats['name']));
                continue;
            }

            //If file is a update script, include
            if ($filename === $this->updateScriptName) {
                $this->log->debug(sprintf('Try to include update script "%s"', $absoluteFilename));
                require($absoluteFilename);

                $this->log->info(sprintf('Update script "%s" included!', $absoluteFilename));
                if (!unlink($absoluteFilename)) {
                    $this->log->warning(sprintf('Could not delete update script "%s"!', $absoluteFilename));
                }
            }
        }

        $zip->close();

        $this->log->notice(sprintf('Update "%s" successfully installed', $version));

        return true;
    }

    /**
     * Update to the latest version
     *
     * @param bool $simulateInstall Check for directory and file permissions before copying files (Default: true)
     * @param bool $deleteDownload Delete download after update (Default: true)
     * @return integer|bool
     * @throws DownloadException
     * @throws ParserException
     * @throws InvalidArgumentException
     */
    public function update(bool $simulateInstall = true, bool $deleteDownload = true)
    {
        $this->log->info('Trying to perform update');

        // Check for latest version
        if ($this->latestVersion === null || count($this->updates) === 0) {
            $this->checkUpdate();
        }

        if ($this->latestVersion === null || count($this->updates) === 0) {
            $this->log->error('Could not get latest version from server!');

            return self::ERROR_VERSION_CHECK;
        }

        // Check if current version is up-to-date
        if (!$this->newVersionAvailable()) {
            $this->log->warning('No update available!');

            return self::NO_UPDATE_AVAILABLE;
        }

        foreach ($this->updates as $update) {
            $this->log->debug(sprintf('Update to version "%s"', $update['version']));

            // Check for temp directory
            if (empty($this->tempDir) || !is_dir($this->tempDir) || !is_writable($this->tempDir)) {
                $this->log->critical(sprintf(
                    'Temporary directory "%s" does not exist or is not writeable!',
                    $this->tempDir
                ));

                return self::ERROR_TEMP_DIR;
            }

            // Check for install directory
            if (empty($this->installDir) || !is_dir($this->installDir) || !is_writable($this->installDir)) {
                $this->log->critical(sprintf(
                    'Install directory "%s" does not exist or is not writeable!',
                    $this->installDir
                ));

                return self::ERROR_INSTALL_DIR;
            }

            $updateFile = $this->tempDir . $update['version'] . '.zip';

            // Download update
            if (!is_file($updateFile)) {
                if (!$this->downloadUpdate($update['url'], $updateFile)) {
                    $this->log->critical(sprintf(
                        'Failed to download update from "%s" to "%s"!',
                        $update['url'],
                        $updateFile
                    ));

                    return self::ERROR_DOWNLOAD_UPDATE;
                }

                $this->log->debug(sprintf('Latest update downloaded to "%s"', $updateFile));
            } else {
                $this->log->info(sprintf('Latest update already downloaded to "%s"', $updateFile));
            }

            // Install update
            $result = $this->install($updateFile, $simulateInstall, $update['version']);
            if ($result === true) {
                $this->runOnEachUpdateFinishCallbacks($update['version'], $simulateInstall);
                if ($deleteDownload) {
                    $this->log->debug(sprintf(
                        'Trying to delete update file "%s" after successfull update',
                        $updateFile
                    ));
                    if (unlink($updateFile)) {
                        $this->log->info(sprintf('Update file "%s" deleted after successfull update', $updateFile));
                    } else {
                        $this->log->error(sprintf(
                            'Could not delete update file "%s" after successfull update!',
                            $updateFile
                        ));

                        return self::ERROR_DELETE_TEMP_UPDATE;
                    }
                }
            } else {
                if ($deleteDownload) {
                    $this->log->debug(sprintf('Trying to delete update file "%s" after failed update', $updateFile));
                    if (unlink($updateFile)) {
                        $this->log->info(sprintf('Update file "%s" deleted after failed update', $updateFile));
                    } else {
                        $this->log->error(sprintf(
                            'Could not delete update file "%s" after failed update!',
                            $updateFile
                        ));
                    }
                }

                return false;
            }
        }

        $this->runOnAllUpdateFinishCallbacks($this->getVersionsToUpdate());

        return true;
    }

    /**
     * Add slash at the end of the path.
     *
     * @param string $dir
     * @return string
     */
    public function addTrailingSlash(string $dir): string
    {
        if (substr($dir, -1) !== DIRECTORY_SEPARATOR) {
            $dir .= DIRECTORY_SEPARATOR;
        }

        return $dir;
    }

    /**
     * Add callback which is executed after each update finished.
     *
     * @param callable $callback
     * @return $this
     */
    public function onEachUpdateFinish(callable $callback): self
    {
        $this->onEachUpdateFinishCallbacks[] = $callback;

        return $this;
    }

    /**
     * Add callback which is executed after all updates finished.
     *
     * @param callable $callback
     * @return $this
     */
    public function setOnAllUpdateFinishCallbacks(callable $callback): self
    {
        $this->onAllUpdateFinishCallbacks[] = $callback;

        return $this;
    }

    /**
     * Run callbacks after each update finished.
     *
     * @param string $updateVersion
     * @param bool $simulate
     * @return void
     */
    private function runOnEachUpdateFinishCallbacks(string $updateVersion, bool $simulate): void
    {
        foreach ($this->onEachUpdateFinishCallbacks as $callback) {
            $callback($updateVersion, $simulate);
        }
    }

    /**
     * Run callbacks after all updates finished.
     *
     * @param array $updatedVersions
     * @return void
     */
    private function runOnAllUpdateFinishCallbacks(array $updatedVersions): void
    {
        foreach ($this->onAllUpdateFinishCallbacks as $callback) {
            $callback($updatedVersions);
        }
    }
}
