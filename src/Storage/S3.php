<?php

namespace Codesleeve\Stapler\Storage;

use Codesleeve\Stapler\Interfaces\Storage as StorageInterface;
use Aws\S3\S3Client;
use Aws\CloudFront\CloudFrontClient;
use Codesleeve\Stapler\Attachment;

class S3 implements StorageInterface
{
    /**
     * The current attachedFile object being processed.
     *
     * @var \Codesleeve\Stapler\Attachment
     */
    public $attachedFile;

    /**
     * The AWS S3Client instance.
     *
     * @var S3Client
     */
    protected $s3Client;

    /**
     * Boolean flag indicating if this attachment's bucket currently exists.
     *
     * @var array
     */
    protected $bucketExists = false;

    /**
     * Constructor method.
     *
     * @param Attachment $attachedFile
     * @param S3Client   $s3Client
     */
    public function __construct(Attachment $attachedFile, S3Client $s3Client)
    {
        $this->attachedFile = $attachedFile;
        $this->s3Client = $s3Client;
    }

    /**
     * Return the url for a file upload.
     *
     * @param string $styleName
     *
     * @return string
     */
    public function url($styleName,$providerName=false)
    {
        if(is_string($providerName)) {
            switch($providerName) {
                case 'cloudfront':
                    return $this->urlCloudfront($styleName);
                    break;
                case 'maxcdn':
                    return $this->urlMaxcdn($styleName);
                    break;
                case 's3':
                    return $this->urlS3($styleName);
                    break;
                default:
                    throw new \RuntimeException("Provider not defined");
            }
        } else {
            //Hacky stuff for Cloudfront
            $cloudfrontConfig = $this->attachedFile->cloudfront;

            //If cloudfront config is enabled, generate signed URL to cloudfront
            if($cloudfrontConfig['enabled']) {
                return $this->urlCloudfront($styleName);

            }

            $maxcdnConfig = $this->attachedFile->maxcdn;
            if($maxcdnConfig['enabled']) {
                return $this->urlMaxcdn($styleName);
            }

            //Generate S3 URL
            return $this->urlS3($styleName);
        }
    }

    private function urlCloudfront($styleName)
    {
        $cloudfrontConfig = $this->attachedFile->cloudfront;
        $cloudFrontClient = CloudFrontClient::factory($cloudfrontConfig);
        return $cloudFrontClient->getSignedUrl([
            'url'=> 'http://'.$cloudfrontConfig['distribution_url'].'/'.urlencode($this->path($styleName)),
            'expires'=> time() + $cloudfrontConfig['expiry_time']
        ]);
    }

    private function urlMaxcdn($styleName)
    {
        $maxcdnConfig = $this->attachedFile->maxcdn;
        return 'http://'.$maxcdnConfig['distribution_url'].'/'.urlencode($this->path($styleName));
    }

    private function urlS3($styleName)
    {
        //Generate S3 URL
        return $this->s3Client->getObjectUrl($this->attachedFile->s3_object_config['Bucket'], $this->path($styleName), null, ['PathStyle' => true]);
    }

    /**
     * Return the key the uploaded file object is stored under within a bucket.
     *
     * @param string $styleName
     *
     * @return string
     */
    public function path($styleName)
    {
        return $this->attachedFile->getInterpolator()->interpolate($this->attachedFile->path, $this->attachedFile, $styleName);
    }

    /**
     * Remove an attached file.
     *
     * @param array $filePaths
     */
    public function remove(array $filePaths)
    {
        if ($filePaths) {
            $this->s3Client->deleteObjects(['Bucket' => $this->attachedFile->s3_object_config['Bucket'], 'Objects' => $this->getKeys($filePaths)]);
        }
    }

    /**
     * Move an uploaded file to it's intended destination.
     *
     * @param string $file
     * @param string $filePath
     */
    public function move($file, $filePath)
    {
        $objectConfig = $this->attachedFile->s3_object_config;
        $fileSpecificConfig = ['Key' => $filePath, 'SourceFile' => $file, 'ContentType' => $this->attachedFile->contentType()];
        $mergedConfig = array_merge($objectConfig, $fileSpecificConfig);

        $this->ensureBucketExists($mergedConfig['Bucket']);
        $this->s3Client->putObject($mergedConfig);

        @unlink($file);
    }

    /**
     * Return an array of paths (bucket keys) for an attachment.
     * There will be one path for each of the attachmetn's styles.
     *
     * @param  $filePaths
     *
     * @return array
     */
    protected function getKeys($filePaths)
    {
        $keys = [];

        foreach ($filePaths as $filePath) {
            $keys[] = ['Key' => $filePath];
        }

        return $keys;
    }

    /**
     * Ensure that a given S3 bucket exists.
     *
     * @param string $bucketName
     */
    protected function ensureBucketExists($bucketName)
    {
        if (!$this->bucketExists) {
            $this->buildBucket($bucketName);
        }
    }

    /**
     * Attempt to build a bucket (if it doesn't already exist).
     *
     * @param string $bucketName
     */
    protected function buildBucket($bucketName)
    {
        if (!$this->s3Client->doesBucketExist($bucketName, true)) {
            $this->s3Client->createBucket(['ACL' => $this->attachedFile->ACL, 'Bucket' => $bucketName, 'LocationConstraint' => $this->attachedFile->region]);
        }

        $this->bucketExists = true;
    }
}
