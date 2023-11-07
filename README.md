# Bitbucket Update Fetcher

The `UpdateFetcher` class is responsible for fetching updates from Bitbucket. It maintains information about the latest version and updates not yet installed. It also uses caching to optimize update requests and a logger instance for logging purposes. The class uses several dependencies including Composer\Semver\Comparator, Desarrolla2\Cache\CacheInterface, Monolog\Logger, and others.

## Installation

- Install the library via composer pickleboxer/bitbucket-update-fetcher
- Create an update file/method in your application with your update routine (see example/update/index.php)

## Usage

To use the `UpdateFetcher` class, you need to instantiate it and then call the appropriate methods. Here's a step-by-step guide on how to use the UpdateFetcher class:

1. Include the necessary files and use the UpdateFetcher class:

```php
require('vendor/autoload.php');
use BitbucketUpdateFetcher\UpdateFetcher;
```

2. Instantiate the UpdateFetcher class:

```php
$fetcher = new UpdateFetcher();
```

3. Set the current version, workspace, repository slug, and access token:

```php
$fetcher->setCurrentVersion('0.1.1');
$fetcher->setWorkspace('workspace');
$fetcher->setRepoSlug('repoSlug');
$fetcher->setAccessToken('your_access_token');
```

4. Update to the latest version:

```php
$result = $fetcher->update();
```

## Development Environment Setup

Follow these steps to open this project in a development container using Visual Studio Code:

1. Install the [Remote - Containers extension](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers) in Visual Studio Code.
2. Clone the project repository to your local machine.
3. Open the project folder in Visual Studio Code.
4. Press `F1` to open the command palette.
5. Select `Remote-Containers: Reopen in Container` from the dropdown.

Once the development container is up and running, you can view the application by navigating to the following URL in your web browser:

```plaintext
localhost:8000/example/
```

## Contributing

Contributions are welcome. Please submit a pull request or create an issue to discuss the changes you want to make.

## License

This project is licensed under the MIT License. See the LICENSE file for more details.
