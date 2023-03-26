<?php

/**
 * Created by jacob.
 * Date: 2016/5/19 0019
 * Time: 下午 17:07
 */

namespace Surpaimb\AliOSS;

use Dingo\Api\Contract\Transformer\Adapter;
use Exception;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Config;
use OSS\Core\OssException;
use OSS\OssClient;
use Log;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

class AliOssAdapter implements FilesystemAdapter
{

    /**
     * @const  VISIBILITY_PUBLIC  public visibility
     */
    const VISIBILITY_PUBLIC = 'public';

    /**
     * @const  VISIBILITY_PRIVATE  private visibility
     */
    const VISIBILITY_PRIVATE = 'private';

    /**
     * @var string|null path prefix
     */
    protected $pathPrefix;

    /**
     * @var string
     */
    protected $pathSeparator = '/';
    /**
     * @var Log debug Mode true|false
     */
    protected $debug;
    /**
     * @var array
     */
    protected static $resultMap = [
        'Body'           => 'raw_contents',
        'Content-Length' => 'size',
        'ContentType'    => 'mimetype',
        'Size'           => 'size',
        'StorageClass'   => 'storage_class',
    ];

    /**
     * @var array
     */
    protected static $metaOptions = [
        'CacheControl',
        'Expires',
        'ServerSideEncryption',
        'Metadata',
        'ACL',
        'ContentType',
        'ContentDisposition',
        'ContentLanguage',
        'ContentEncoding',
    ];

    protected static $metaMap = [
        'CacheControl'         => 'Cache-Control',
        'Expires'              => 'Expires',
        'ServerSideEncryption' => 'x-oss-server-side-encryption',
        'Metadata'             => 'x-oss-metadata-directive',
        'ACL'                  => 'x-oss-object-acl',
        'ContentType'          => 'Content-Type',
        'ContentDisposition'   => 'Content-Disposition',
        'ContentLanguage'      => 'response-content-language',
        'ContentEncoding'      => 'Content-Encoding',
    ];

    //Aliyun OSS Client OssClient
    protected $client;
    //bucket name
    protected $bucket;

    protected $endPoint;

    protected $cdnDomain;

    protected $ssl;

    protected $isCname;

    //配置
    protected $options = [
        'Multipart'   => 128
    ];


    /**
     * AliOssAdapter constructor.
     *
     * @param OssClient $client
     * @param string    $bucket
     * @param string    $endPoint
     * @param bool      $ssl
     * @param bool      $isCname
     * @param bool      $debug
     * @param null      $prefix
     * @param array     $options
     */
    public function __construct(
        OssClient $client,
        $bucket,
        $endPoint,
        $ssl,
        $isCname = false,
        $debug = false,
        $cdnDomain,
        $prefix = null,
        array $options = []
    ) {
        $this->debug = $debug;
        $this->client = $client;
        $this->bucket = $bucket;
        $this->setPathPrefix($prefix);
        $this->endPoint = $endPoint;
        $this->ssl = $ssl;
        $this->isCname = $isCname;
        $this->cdnDomain = $cdnDomain;
        $this->options = array_merge($this->options, $options);
    }

    /**
     * Get the OssClient bucket.
     *
     * @return string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Get the OSSClient instance.
     *
     * @return OssClient
     */
    public function getClient()
    {
        return $this->client;
    }



    public function directoryExists(string $path): bool
    {
        return $this->client->doesObjectExist($this->bucket, $path);
    }

    public function fileExists(string $path): bool
    {
        return $this->client->doesObjectExist($this->bucket, $path);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptions($this->options, $config);

        try {
            $this->client->putObject($this->bucket, $object, $contents, $options);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            // return false;
        }
        // return $this->normalizeResponse($options, $path);
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $options = $this->getOptions($this->options, $config);
        $resource = stream_get_contents($contents);

        $this->write($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $object = $this->applyPathPrefix($source);
        $newObject = $this->applyPathPrefix($destination);
        try {
            $this->client->copyObject($this->bucket, $object, $this->bucket, $newObject);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            // return false;
        }

        // return true;
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->client->copyObject($this->bucket, $source, $this->bucket, $destination);
        $this->client->deleteObject($this->bucket, $source);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): void
    {
        $bucket = $this->bucket;
        $object = $this->applyPathPrefix($path);

        try {
            $this->client->deleteObject($bucket, $object);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            // return false;
        }

        // return ! $this->has($path);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $this->deleteDirectory($dirname);
    }

    public function deleteDirectory(string $path): void
    {
        $dirname = rtrim($this->applyPathPrefix($path), '/') . '/';
        $dirObjects = $this->listDirObjects($path, true);

        if (count($dirObjects['objects']) > 0) {

            foreach ($dirObjects['objects'] as $object) {
                $objects[] = $object['Key'];
            }

            try {
                $this->client->deleteObjects($this->bucket, $objects);
            } catch (OssException $e) {
                $this->logErr(__FUNCTION__, $e);
                // return false;
            }
        }

        try {
            $this->client->deleteObject($this->bucket, $dirname);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            // return false;
        }

        // return true;
    }

    /**
     * 列举文件夹内文件列表；可递归获取子文件夹；
     * @param string $dirname 目录
     * @param bool $recursive 是否递归
     * @return mixed
     * @throws OssException
     */
    public function listDirObjects($dirname = '', $recursive =  false)
    {
        $delimiter = '/';
        $nextMarker = '';
        $maxkeys = 1000;

        //存储结果
        $result = [];

        while (true) {
            $options = [
                'delimiter' => $delimiter,
                'prefix'    => $dirname,
                'max-keys'  => $maxkeys,
                'marker'    => $nextMarker,
            ];

            try {
                $listObjectInfo = $this->client->listObjects($this->bucket, $options);
            } catch (OssException $e) {
                $this->logErr(__FUNCTION__, $e);
                // return false;
                throw $e;
            }

            $nextMarker = $listObjectInfo->getNextMarker(); // 得到nextMarker，从上一次listObjects读到的最后一个文件的下一个文件开始继续获取文件列表
            $objectList = $listObjectInfo->getObjectList(); // 文件列表
            $prefixList = $listObjectInfo->getPrefixList(); // 目录列表

            if (!empty($objectList)) {
                foreach ($objectList as $objectInfo) {

                    $object['Prefix']       = $dirname;
                    $object['Key']          = $objectInfo->getKey();
                    $object['LastModified'] = $objectInfo->getLastModified();
                    $object['eTag']         = $objectInfo->getETag();
                    $object['Type']         = $objectInfo->getType();
                    $object['Size']         = $objectInfo->getSize();
                    $object['StorageClass'] = $objectInfo->getStorageClass();

                    $result['objects'][] = $object;
                }
            } else {
                $result["objects"] = [];
            }

            if (!empty($prefixList)) {
                foreach ($prefixList as $prefixInfo) {
                    $result['prefix'][] = $prefixInfo->getPrefix();
                }
            } else {
                $result['prefix'] = [];
            }

            //递归查询子目录所有文件
            if ($recursive) {
                foreach ($result['prefix'] as $pfix) {
                    $next  =  $this->listDirObjects($pfix, $recursive);
                    $result["objects"] = array_merge($result['objects'], $next["objects"]);
                }
            }

            //没有更多结果了
            if ($nextMarker === '') {
                break;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        $this->createDirectory($dirname, $config);
    }
    public function createDirectory(string $path, Config $config): void
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptionsFromConfig($config);

        try {
            $this->client->createObjectDir($this->bucket, $object, $options);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            // return false;
        }

        // return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $object = $this->applyPathPrefix($path);
        // $acl = ( $visibility === self::VISIBILITY_PUBLIC ) ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;

        // $this->client->putObjectAcl($this->bucket, $object, $acl);
        $this->client->putObjectAcl(
            $this->bucket,
            $object,
            ($visibility == 'public') ? 'public-read' : 'private'
        );

        // compact('visibility');
    }

    public function visibility(string $path): FileAttributes
    {
        $response = $this->client->getObjectAcl($this->bucket, $path);
        return new FileAttributes($path, null, $response);
    }

    public function mimeType(string $path): FileAttributes
    {
        $response = $this->client->getObjectMeta($this->bucket, $path);
        return new FileAttributes($path, null, null, null, $response['content-type']);
    }

    public function lastModified(string $path): FileAttributes
    {
        $response = $this->client->getObjectMeta($this->bucket, $path);
        return new FileAttributes($path, null, null, $response['last-modified']);
    }

    public function fileSize(string $path): FileAttributes
    {
        $response = $this->client->getObjectMeta($this->bucket, $path);
        $fileSize = null;
        if (isset($response['content-length'])) {
            $fileSize = (int) $response['content-length'];
        }
        return new FileAttributes($path, $fileSize);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $directory = rtrim($path, '\\/');

        $result = [];
        $nextMarker = '';
        while (true) {
            // max-keys 用于限定此次返回object的最大数，如果不设定，默认为100，max-keys取值不能大于1000。
            // prefix   限定返回的object key必须以prefix作为前缀。注意使用prefix查询时，返回的key中仍会包含prefix。
            // delimiter是一个用于对Object名字进行分组的字符。所有名字包含指定的前缀且第一次出现delimiter字符之间的object作为一组元素
            // marker   用户设定结果从marker之后按字母排序的第一个开始返回。
            $options = [
                'max-keys' => 1000,
                'prefix' => $directory . '/',
                'delimiter' => '/',
                'marker' => $nextMarker,
            ];
            $res = $this->client->listObjects($this->bucket, $options);

            // 得到nextMarker，从上一次$res读到的最后一个文件的下一个文件开始继续获取文件列表
            $nextMarker = $res->getNextMarker();
            $prefixList = $res->getPrefixList(); // 目录列表
            $objectList = $res->getObjectList(); // 文件列表
            if ($prefixList) {
                foreach ($prefixList as $value) {
                    $result[] = [
                        'type' => 'dir',
                        'path' => $value->getPrefix(),
                    ];
                    if ($deep) {
                        $result = array_merge($result, $this->listContents($value->getPrefix(), $deep));
                    }
                }
            }
            if ($objectList) {
                foreach ($objectList as $value) {
                    if (($value->getSize() === 0) && ($value->getKey() === $directory . '/')) {
                        continue;
                    }
                    $result[] = [
                        'type' => 'file',
                        'path' => $value->getKey(),
                        'timestamp' => strtotime($value->getLastModified()),
                        'size' => $value->getSize(),
                    ];
                }
            }
            if ($nextMarker === '') {
                break;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        $object = $this->applyPathPrefix($path);

        return $this->client->doesObjectExist($this->bucket, $object);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        return $this->client->getObject($this->bucket, $path);
    }

    /**
     * {@inheritdoc}
     */
    public function readStream(string $path)
    {
        $body = $this->read($path);
        $resource = fopen('php://temp', 'r+');
        if ($body !== '') {
            fwrite($resource, $body);
            fseek($resource, 0);
        }

        return $resource;
    }


    /**
     * {@inheritdoc}
     */
    // public function listContents($directory = '', $recursive = false)
    // {
    //     $dirObjects = $this->listDirObjects($directory, true);
    //     $contents = $dirObjects["objects"];

    //     $result = array_map([$this, 'normalizeResponse'], $contents);
    //     $result = array_filter($result, function ($value) {
    //         return $value['path'] !== false;
    //     });

    //     return Util::emulateDirectories($result);
    // }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $object = $this->applyPathPrefix($path);

        try {
            $objectMeta = $this->client->getObjectMeta($this->bucket, $object);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        return $objectMeta;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        $object = $this->getMetadata($path);
        $object['size'] = $object['content-length'];
        return $object;
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        if ($object = $this->getMetadata($path))
            $object['mimetype'] = $object['content-type'];
        return $object;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        if ($object = $this->getMetadata($path))
            $object['timestamp'] = strtotime($object['last-modified']);
        return $object;
    }

    /**
     * {@inheritdoc}
     */
    public function getVisibility($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $acl = $this->client->getObjectAcl($this->bucket, $object);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        if ($acl == OssClient::OSS_ACL_TYPE_PUBLIC_READ) {
            $res['visibility'] = self::VISIBILITY_PUBLIC;
        } else {
            $res['visibility'] = self::VISIBILITY_PRIVATE;
        }

        return $res;
    }


    /**
     * @param $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        if (!$this->has($path)) throw new Exception($path . ' not found');
        return ($this->ssl ? 'https://' : 'http://') . ($this->isCname ? ($this->cdnDomain == '' ? $this->endPoint : $this->cdnDomain) : $this->bucket . '.' . $this->endPoint) . '/' . ltrim($path, '/');
    }

    /**
     * The the ACL visibility.
     *
     * @param string $path
     *
     * @return string
     */
    protected function getObjectACL($path)
    {
        $metadata = $this->getVisibility($path);

        return $metadata['visibility'] === self::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
    }

    /**
     * Get options for a OSS call. done
     *
     * @param array  $options
     *
     * @return array OSS options
     */
    protected function getOptions(array $options = [], Config $config = null)
    {
        $options = array_merge($this->options, $options);

        if ($config) {
            $options = array_merge($options, $this->getOptionsFromConfig($config));
        }

        return array(OssClient::OSS_HEADERS => $options);
    }

    /**
     * Retrieve options from a Config instance. done
     *
     * @param Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = [];

        foreach (static::$metaOptions as $option) {
            if (!$config->get($option)) {
                continue;
            }
            $options[static::$metaMap[$option]] = $config->get($option);
        }

        if ($visibility = $config->get('visibility')) {
            // For local reference
            // $options['visibility'] = $visibility;
            // For external reference
            $options['x-oss-object-acl'] = $visibility === self::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
        }

        if ($mimetype = $config->get('mimetype')) {
            // For local reference
            // $options['mimetype'] = $mimetype;
            // For external reference
            $options['Content-Type'] = $mimetype;
        }

        return $options;
    }

    /**
     * @param $fun string function name : __FUNCTION__
     * @param $e
     */
    protected function logErr($fun, $e)
    {
        if ($this->debug) {
            Log::error($fun . ": FAILED");
            Log::error($e->getMessage());
        }
    }

    /**
     * Set the path prefix.
     *
     * @param string $prefix
     *
     * @return void
     */
    public function setPathPrefix($prefix)
    {
        $prefix = (string) $prefix;

        if ($prefix === '') {
            $this->pathPrefix = null;

            return;
        }

        $this->pathPrefix = rtrim($prefix, '\\/') . $this->pathSeparator;
    }

    /**
     * Get the path prefix.
     *
     * @return string|null path prefix or null if pathPrefix is empty
     */
    public function getPathPrefix()
    {
        return $this->pathPrefix;
    }

    /**
     * Prefix a path.
     *
     * @param string $path
     *
     * @return string prefixed path
     */
    public function applyPathPrefix($path)
    {
        return $this->getPathPrefix() . ltrim($path, '\\/');
    }

    /**
     * Remove a path prefix.
     *
     * @param string $path
     *
     * @return string path without the prefix
     */
    public function removePathPrefix($path)
    {
        return substr($path, strlen((string) $this->getPathPrefix()));
    }

    private function getOssOptions(Config $config): array
    {
        $options = [];
        if ($headers = $config->get('headers')) {
            $options['headers'] = $headers;
        }

        if ($contentType = $config->get('Content-Type')) {
            $options['Content-Type'] = $contentType;
        }

        if ($contentMd5 = $config->get('Content-Md5')) {
            $options['Content-Md5'] = $contentMd5;
            $options['checkmd5'] = false;
        }
        return $options;
    }
}
