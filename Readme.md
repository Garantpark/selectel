# Selectel Cloud Storage API
[![Latest Stable Version](https://poser.pugx.org/garantpark/selectel/version)](https://packagist.org/packages/garantpark/selectel)
[![License](https://poser.pugx.org/garantpark/selectel/license)](https://packagist.org/packages/garantpark/selectel/)

[Selectel](http://selectel.ru)'s [Cloud Storage](https://selectel.ru/services/cloud-storage/) API Client.
## Install
Via Composer
``` bash
$ composer require garantpark/selectel
```
## Basic Usage
``` php
<?php
use GarantPark\Selectel\Client;
$client = new Client($username, $password);
$client->createContainer('new-container', 'public');

$localPath = realpath('./image.png');
$remotePath = '/images/image.png';
$client->uploadFile('new-container', $localPath, $remotePath);
// File image.png is now available at http://XXX.selcdn.ru/new-container/images/image.png
// XXX in URL is your Storage ID
```

## Available Methods
### [Storage info](https://support.selectel.ru/storage/api_info/#id4)
``` php
$storageInfo = $client->getStorageInfo();
```
### [Containers list](https://support.selectel.ru/storage/api_info/#id5)
``` php
$containers = $client->getContainers();
```
### [Create new container](https://support.selectel.ru/storage/api_info/#id6)
``` php
$containerType = 'public'; // or 'private'
$metaData = []; // Any meta data (will be stored as "X-Container-Meta-{KEY}: {Value}" header)
$result = $client->createContainer('container-name', $containerType, $metaData);
```
### [Container info](https://support.selectel.ru/storage/api_info/#id7)
``` php
$containerInfo = $client->getContainerInfo('container-name');
```
If container does not exists, `\GarantPark\Selectel\Exceptions\ContainerNotFoundException` will be thrown. 
### [Delete container](https://support.selectel.ru/storage/api_info/#id9)
``` php
$client->deleteContainer('container-name');
```
If container does not exists, `\GarantPark\Selectel\Exceptions\ContainerNotFoundException` will be thrown.

Also, if container is not empty, `\GarantPark\Selectel\Exceptions\ContainerNotEmptyException` will be thrown.
### [Get container files](https://support.selectel.ru/storage/api_info/#id11)
``` php
$params = [
    'path' => '/images/', // get files from /images/ direcory only
];
// More $params values can be found in Selectel's API docs (see link above).
$files = $client->getContainerFiles('container-name', $params);
```
If container does not exists, `\GarantPark\Selectel\Exceptions\ContainerNotFoundException` will be thrown.
### [Upload file](https://support.selectel.ru/storage/api_info/#id13)
``` php
$localFile = realpath('../../images/logo-big.png');
$remotePath = '/images/logos/logo-big.png';
$file = $client->uploadFile('container-name', $localFile, $remotePath);
```
If local file is not readable, `\GarantPark\Selectel\Exceptions\LocalFileNotAvailableException` will be thrown.

If upload fails, `GarantPark\Selectel\Exceptions\FileUploadFailedException` will be thrown.
### [Archive upload & unpack](https://support.selectel.ru/storage/api_info/#id14)
Use same method as for file upload but add third argument `$params` with `extract-archive` key.
``` php
$localFile = realpath('../../backups/database-backup-latest.tar.gz');
$remotePath = '/backups/database/' . date('Y-m-d') . '.tar.gz';
$params = [
    'extract-archive' => 'tar.gz',
];
$archive = $client->uploadFile('container-name', $localFile, $remotePath, $params);
// $archive will contain additional field 'extract.filesCount'.
```
If upload fails, `GarantPark\Selectel\Exceptions\FileUploadFailedException` will be thrown.

If archive extraction fails, `GarantPark\Selectel\Exceptions\ArchiveExtractFailedException` will be thrown.

Supported archive types:
- tar
- tar.gz
- tar.bz2
### [Add metadata to a file](https://support.selectel.ru/storage/api_info/#id15)
``` php
$remotePath = '/backups/database/' . date('Y-m-d') . 'tar.gz'; // cloud path
$meta = [
    'Created-By' => 'Backup Manager',
];
$client->setFileMetaData('container-name', $remotePath, $meta);
```
This will result in adding `X-Object-Meta-Created-By` header to file with value `Backup Manager`.

If container does not exists, `GarantPark\Selectel\Exceptions\ContainerNotFoundException` will be thrown.
### [Copy file](https://support.selectel.ru/storage/api_info/#id16)
``` php
$srcContainer = 'images-old'; // source container name
$srcPath = '/images/logos/logo-big.png'; // source path (in container)
$dstContainer = 'images-new'; // destination container name
$dstPath = '/images/logos/logo-big-copied.png'; // destination path (in container)
// optional metadata
$meta = [
    'Copied-At' => date('Y-m-d H:i:s'), // add "X-Object-Meta-Copied-At: date" header to copied file
];
$client->copyFile($srcContainer, $srcPath, $dstContainer, $dstPath, $meta);
```
If source or destination path does not exists, `GarantPark\Selectel\Exceptions\ObjectNotFoundException` will be thrown.
### [Delete file](https://support.selectel.ru/storage/api_info/#id17)
``` php
$client->deleteFile('container-name', '/path/to/fille.png');
```
If file does not exists, `GarantPark\Selectel\Exceptions\FileNotFoundException` will be thrown.
### [Create symlink to a file or direcory](https://support.selectel.ru/storage/api_info/#symlink)
``` php
$linkPath = '/public/images/structure.png'; // link path (where to store link)
$linkSource = '/private-images/structure.png'; // original file
$params = [
    'type' => 'symlink', // link type, see information below, required
    'deleteAt' => time() + (60 * 60), // link will be deleted in one hour, optional
    'password' => sha1('mySecurePassword' . urlencode($linkSource)), // password, optional
];
$client->createSymlink('container-name', $linkPath, $linkSource, $params);
```
Available symlink types:
- `symlink`: basic link,
- `onetime-symlink`: onetime link (will be deleted after first visit),
- `symlink+secure`: link with password protection
- `onetime-symlink+secure`: onetime link with password protection

If you're using password protected link, user must use `_sslk` query param to get access to this file: `http://XXXX.selcdn.ru/container-name/public/images/structure.png?sslk=mySecurePassword`
### [Generate link for a private file download](https://support.selectel.ru/storage/api_info/#id19)
First of all, you need to set up secret key to an account or a container (one time).
``` php
$containerName = 'container-name';
// container-specific secret key
$client->setObjectSecretKey($containerName, 'myContainerSecretKey');
// or account-wide secret key
$client->setObjectSecretKey('/', 'myAccountSecretKey');
```
If container does not exists, `GarantPark\Selectel\Exceptions\ObjectNotFoundException` will be thrown.

After doing so, you'll be able to generate signed links.
``` php
$remotePath = '/container-name/private-files/account.txt';
$expires = time() + (60 * 60); // expires in 1 hour
$secretKey = 'myAccountSecretKey'; // account or container secret key
$signedUrl = $client->generateSignedLink($remotePath, $expires, $secretKey);
```
## License
The MIT License (MIT). Please see [License file](LICENSE.md) for more information.