<?php

namespace GarantPark\Selectel;

use InvalidArgumentException;
use GarantPark\Selectel\Exceptions\AuthFailedException;
use GarantPark\Selectel\Exceptions\GeneralException;
use GarantPark\Selectel\Exceptions\ContainerNotFoundException;
use GarantPark\Selectel\Exceptions\ContainerNotEmptyException;
use GarantPark\Selectel\Exceptions\LocalFileNotAvailableException;
use GarantPark\Selectel\Exceptions\FileNotFoundException;
use GarantPark\Selectel\Exceptions\ArchiveExtractFailedException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException as GuzzleClientException;

class Client
{
    const AUTH_URL = 'https://auth.selcdn.ru/';
    const AUTH_STATUS_CODE_OK = 204;
    const CONTAINER_STATUS_CODE_CREATED = 201;
    const DELETE_CONTAINER_STATUS_NOT_EMPTY = 409;
    const DEFAULT_LIMIT = 10000;
    const STATUS_NOT_FOUND = 404;
    const STATUS_UNPROCESSABLE_ENTITY = 422;

    protected $storage = [];
    protected $containers = [];
    protected $http;

    /**
     * Creates API Client.
     *
     * @param string $username
     * @param string $password
     *
     * @throws \InvalidArgumentException
     * @throws \GarantPark\Selectel\Exceptions\AuthFailedException
     */
    public function __construct($username, $password)
    {
        if (empty($username)) {
            throw new InvalidArgumentException('Username is missing.');
        }

        if (empty($password)) {
            throw new InvalidArgumentException('Password is missing.');
        }

        $authClient = new GuzzleClient([
            'base_uri' => self::AUTH_URL,
        ]);

        try {
            $response = $authClient->get('/', [
                'headers' => [
                    'X-Auth-User' => $username,
                    'X-Auth-Key' => $password,
                ],
            ]);
        } catch (GuzzleClientException $e) {
            throw new AuthFailedException('Selectel authorization failed.');
        }

        if ($response->getStatusCode() !== self::AUTH_STATUS_CODE_OK) {
            throw new AuthFailedException('Selectel authorization failed.');
        }

        $authToken = $response->getHeader('X-Auth-Token')[0];
        $storageUrl = $response->getHeader('X-Storage-Url')[0];

        $this->initHttp($authToken, $storageUrl);
    }

    /**
     * Creates HTTP Client to work with Storage API.
     *
     * @param string $authToken
     * @param string $storageUrl
     */
    private function initHttp($authToken, $storageUrl)
    {
        $this->http = new GuzzleClient([
            'base_uri' => $storageUrl,
            'headers' => [
                'X-Auth-Token' => $authToken,
            ],
        ]);
    }

    /**
     * Returns basic storage info.
     *
     * @throws \GarantPark\Selectel\Exceptions\GeneralException
     *
     * @return array
     */
    public function getStorageInfo()
    {
        $response = $this->http->head('/');

        if (!$response->hasHeader('X-Account-Bytes-Used')) {
            throw new GeneralException('Unable to fetch storage info');
        }

        $storage = [
            'containersCount' => $response->getHeader('X-Account-Container-Count'),
            'objectsCount' => $response->getHeader('X-Account-Object-Count'),
            'totalSize' => $response->getHeader('X-Account-Bytes-Used'),
            'bytesTransfered' => $response->getHeader('X-Transfered-Bytes'),
            'bytesReceived' => $response->getHeader('X-Received-Bytes'),
        ];

        return $this->convertHeaders($storage);
    }

    /**
     * Returns containers list.
     *
     * @param int    $limit  = 10000
     * @param string $marker = ''
     *
     * @return array
     */
    public function getContainers($limit = 10000, $marker = '')
    {
        $response = $this->http->get('/', [
            'query' => ['limit' => $limit, 'marker' => $marker, 'format' => 'json'],
        ]);

        $list = json_decode($response->getBody(), true);
        $containers = [];

        return array_map(function ($container) {
            return [
                'name' => $container['name'],
                'objectsCount' => $container['count'],
                'size' => $container['bytes'],
                'bytesReceived' => $container['rx_bytes'],
                'bytesTransfered' => $container['tx_bytes'],
            ];
        }, $list);
    }

    /**
     * Creates new container.
     *
     * @param string $name
     * @param string $type
     * @param array  $metaData = []
     *
     * @return bool
     */
    public function createContainer($name, $type, array $metaData = [])
    {
        $headers = ['X-Container-Meta-Type' => $type];

        if ($metaData) {
            foreach ($metaData as $key => $value) {
                $headers['X-Container-Meta-'.$key] = $value;
            }
        }

        $response = $this->http->put('/'.$name, [
            'headers' => $headers,
        ]);

        if ($response->getStatusCode() === self::CONTAINER_STATUS_CODE_CREATED) {
            return true;
        }

        return false;
    }

    private function convertHeaders($item)
    {
        foreach ($item as $k => $v) {
            if (is_array($v) && count($v) === 1) {
                $item[$k] = $v[0];
            } elseif (count($v) === 0) {
                $item[$k] = 0;
            }
        }

        return $item;
    }

    /**
     * Returns container info.
     *
     * @param string $name
     *
     * @throws \GarantPark\Selectel\Exceptions\GeneralException
     *
     * @return array
     */
    public function getContainerInfo($name)
    {
        $response = $this->http->head('/'.$name);

        if (!$response->hasHeader('X-Container-Object-Count')) {
            throw new GeneralException('Container was not found.');
        }

        $container = [
            'objectsCount' => $response->getHeader('X-Container-Object-Count'),
            'size' => $response->getHeader('X-Container-Bytes-Used'),
            'bytesTransfered' => $response->getHeader('X-Transfered-Bytes'),
            'bytesReceived' => $response->getHeader('X-Received-Bytes'),
            'type' => $response->getHeader('X-Container-Meta-Type'),
            'domains' => $response->getHeader('X-Container-Meta-Domains'),
            'meta' => [],
        ];

        return $this->convertHeaders($container);
    }

    /**
     * Deletes container.
     *
     * @param string $name
     *
     * @throws \GarantPark\Selectel\Exceptions\ContainerNotFoundException
     * @throws \GarantPark\Selectel\Exceptions\ContainerNotEmptyException
     *
     * @return bool
     */
    public function deleteContainer($name)
    {
        try {
            $response = $this->http->delete('/'.$name);
        } catch (GuzzleClientException $e) {
            if ($e->getCode() === self::STATUS_NOT_FOUND) {
                throw new ContainerNotFoundException('Container was not found.');
            }
        }

        switch ($response->getStatusCode()) {
            case self::DELETE_CONTAINER_STATUS_NOT_EMPTY:
                throw new ContainerNotEmptyException('Container is not empty.');
        }

        return true;
    }

    /**
     * Returns container files lsit.
     *
     * @param string $name
     * @param array  $params = []
     *
     * @throws \GarantPark\Selectel\Exceptions\ContainerNotFoundException
     *
     * @return array
     */
    public function getContainerFiles($name, array $params = [])
    {
        $params['format'] = 'json';

        try {
            $response = $this->http->get('/'.$name, [
                'query' => $params,
            ]);
        } catch (GuzzleClientException $e) {
            if ($e->getCode() === self::STATUS_NOT_FOUND) {
                throw new ContainerNotFoundException('Container was not found.');
            }
        }

        $list = json_decode($response->getBody(), true);

        return array_map(function ($file) {
            return [
                'name' => $file['name'],
                'size' => $file['bytes'],
                'contentType' => $file['content_type'],
                'downloadsCount' => $file['downloaded'],
                'hash' => $file['hash'],
                'lastModified' => $file['last_modified'],
            ];
        }, $list);
    }

    /**
     * Uploads new file to container.
     *
     * @param string $container
     * @param string $localPath
     * @param string $remotePath
     * @param array  $params     = []
     *
     * @throws \GarantPark\Selectel\Exceptions\LocalFileNotAvailableException
     * @throws \GarantPark\Selectel\Exceptions\FileUploadFailedException
     *
     * @return array
     */
    public function uploadFile($container, $localPath, $remotePath, array $params = [])
    {
        if (!is_readable($localPath)) {
            throw new LocalFileNotAvailableException('Local path is not readable');
        }

        $body = fopen($localPath, 'r');
        $fileSize = filesize($localPath);
        $remotePath = ltrim($remotePath, '/');
        $fullRemotePath = '/'.$container.'/'.$remotePath;

        try {
            $response = $this->http->put($fullRemotePath, [
                'body' => $body,
                'headers' => [
                    'Content-Length' => $fileSize,
                    'Accept' => 'application/json',
                ],
                'query' => $params,
            ]);
        } catch (GuzzleClientException $e) {
            if ($e->getCode() === self::STATUS_UNPROCESSABLE_ENTITY) {
                throw new FileUploadFailedException('Unable to upload file.');
            }
        }

        $file = [
            'path' => $remotePath,
            'fullPath' => $fullRemotePath,
        ];

        if (!empty($params['extract-archive'])) {
            $extractReport = json_decode($response->getBody(), true);

            if ($extractReport['Response Status'] === '400 Bad Request') {
                throw new ArchiveExtractFailedException($extractReport['Response Body']);
            }

            $file['extract'] = [
                'filesCount' => $extractReport['Number Files Created'],
            ];
        }

        return $file;
    }

    /**
     * Sets file metadata.
     *
     * @param string $container
     * @param string $remotePath
     * @param array  $meta       = []
     *
     * @throws \GarantPark\Selectel\Exceptions\ContainerNotFoundException
     *
     * @return bool
     */
    public function setFileMetaData($container, $remotePath, array $meta = [])
    {
        $headers = [];

        foreach ($meta as $key => $value) {
            $headers['X-Object-Meta-'.$key] = $value;
        }

        try {
            $response = $this->http->post('/'.$container.'/'.ltrim($remotePath, '/'), [
                'headers' => $headers,
            ]);
        } catch (GuzzleClientException $e) {
            if ($e->getCode() === self::STATUS_NOT_FOUND) {
                throw new ContainerNotFoundException('Container was not found.');
            }
        }

        return true;
    }

    /**
     * Copy file to current/another container.
     *
     * @param string $srcContainer
     * @param string $srcPath
     * @param string $dstContainer
     * @param string $dstPath
     * @param array  $meta         = []
     *
     * @throws \GarantPark\Selectel\Exceptions\ObjectNotFoundException
     *
     * @return bool
     */
    public function copyFile($srcContainer, $srcPath, $dstContainer, $dstPath, array $meta = [])
    {
        $fullSrcPath = '/'.$srcContainer.'/'.ltrim($srcPath, '/');
        $fullDstPath = '/'.$dstContainer.'/'.ltrim($dstPath, '/');

        $headers = [
            'X-Copy-From' => $fullSrcPath,
            'Content-Length' => 0,
        ];

        if ($meta) {
            foreach ($meta as $k => $v) {
                $headers['X-Object-Meta-'.$k] = $v;
            }
        }

        try {
            $response = $this->http->put($fullDstPath, [
                'headers' => $headers,
            ]);
        } catch (GuzzleClientException $e) {
            if ($e->getCode() === self::STATUS_NOT_FOUND) {
                throw new ObjectNotFoundException('Source or destination object was not found.');
            }
        }

        return true;
    }

    /**
     * Deletes file from container.
     *
     * @param string $container
     * @param string $remotePath
     *
     * @throws \GarantPark\Selectel\Exceptions\FileNotFoundException
     *
     * @return bool
     */
    public function deleteFile($container, $remotePath)
    {
        $fullPath = '/'.$container.'/'.ltrim($remotePath, '/');

        try {
            $response = $this->http->delete($fullPath);
        } catch (GuzzleClientException $e) {
            if ($e->getCode() === self::STATUS_NOT_FOUND) {
                throw new FileNotFoundException('File was not found.');
            }
        }

        return true;
    }

    /**
     * Sets headers to container or file.
     *
     * @param string $objectPath
     * @param array  $headers
     *
     * @throws \GarantPark\Selectel\Exceptions\ObjectNotFoundException
     *
     * @return bool
     */
    public function setObjectHeaders($objectPath, array $headers)
    {
        $requestHeaders = [];

        foreach ($headers as $k => $v) {
            $requestHeaders['X-Container-Meta-'.$k] = $v;
        }

        try {
            $response = $this->http->post('/'.$objectPath, [
                'headers' => $requestHeaders,
            ]);
        } catch (GuzzleClientException $e) {
            if ($e->getCode() === self::STATUS_NOT_FOUND) {
                throw new ObjectNotFoundException('Object was not found.');
            }
        }

        return true;
    }

    /**
     * Creates symlink to a file.
     *
     * @param string $container
     * @param string $linkPath
     * @param string $linkSource
     * @param array  $params     = []
     *
     * @return bool
     */
    public function createSymlink($container, $linkPath, $linkSource, array $params = [])
    {
        $headers = [
            'X-Object-Meta-Location' => $linkSource,
            'Content-Length' => 0,
        ];

        if (!empty($params['type'])) {
            $headers['Content-Type'] = 'x-storage/'.strtolower($params['type']);
        }

        if (!empty($params['deleteAt'])) {
            $headers['X-Object-Meta-Delete-At'] = $params['deleteAt'];
        }

        if (!empty($params['password'])) {
            $headers['X-Object-Meta-Link-Key'] = sha1($params['password'].$linkSource);
        }

        $fullLinkPath = '/'.$container.'/'.ltrim($linkPath, '/');

        $response = $this->http->put($fullLinkPath, [
            'headers' => $headers,
        ]);

        return true;
    }

    /**
     * Sets secret key for signed links generation.
     * Key can be set to account or specific container.
     *
     * @param string $objectPath
     * @param string $secretKey
     *
     * @throws \GarantPark\Selectel\Exceptions\ObjectNotFoundException
     *
     * @return bool
     */
    public function setObjectSecretKey($objectPath, $secretKey)
    {
        $fullPath = '/'.ltrim($objectPath, '/');
        $headers = [];

        if ($fullPath === '/') {
            $headers['X-Account-Meta-Temp-URL-Key'] = $secretKey;
        } else {
            $headers['X-Container-Meta-Temp-URL-Key'] = $secretKey;
        }

        try {
            $this->http->post($fullPath, [
                'headers' => $headers,
            ]);
        } catch (GuzzleClientException $e) {
            if ($e->getCode() === self::STATUS_NOT_FOUND) {
                throw new ObjectNotFoundException('Object was not found.');
            }
        }

        return true;
    }

    /**
     * Generates signed link. Requires already installed secret key.
     *
     * @param string $url
     * @param int    $expires
     * @param string $secretKey
     *
     * @return string
     */
    public function generateSignedLink($url, $expires, $secretKey)
    {
        $method = 'GET';
        $signBody = sprintf("%s\n%s\n%s", $method, $expires, $url);
        $signature = hash_hmac('sha1', $body, $secretKey);

        $url .= (strpos($url, '?') !== false ? '&' : '?');
        $url .= 'temp_url_sig='.$signature.'&temp_url_expires='.$expires;

        return $url;
    }
}
