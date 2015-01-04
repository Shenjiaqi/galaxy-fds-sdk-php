<?php
/**
 * Created by IntelliJ IDEA.
 * User: wuzesheng
 * Date: 3/24/14
 * Time: 12:00 AM
 */

namespace FDS;

use FDS\auth\Common;
use FDS\auth\signature\Signer;
use FDS\model\AccessControlList;
use FDS\model\AccessControlPolicy;
use FDS\model\FDSBucket;
use FDS\model\FDSObject;
use FDS\model\FDSObjectListing;
use FDS\model\FDSObjectMetadata;
use FDS\model\FDSObjectSummary;
use FDS\model\Grant;
use FDS\model\Grantee;
use FDS\model\Owner;
use FDS\model\PutObjectResult;
use FDS\model\SubResource;
use Httpful\Http;
use Httpful\Mime;
use Httpful\Request;

class GalaxyFDSClient implements GalaxyFDS {

  const DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';
  const SIGN_ALGORITHM = "sha1";
  const HTTP_OK = 200;
  const HTTP_NOT_FOUND = 404;
  const APPLICATION_OCTET_STREAM = "application/octet-stream";

  private $credential;
  private $fds_base_uri = Common::DEFAULT_FDS_SERVICE_BASE_URI;
  private $delimiter = "/";

  public function __construct($credential, $server_base_uri = "") {
    $this->credential = $credential;
    if (strlen($server_base_uri) > 0) {
      $this->fds_base_uri = $server_base_uri;
    }
  }

  public function listBuckets() {
    $uri = $this->formatUri("");
    $headers = $this->prepareRequestHeader($uri, Http::GET, NULL);
    $response = Request::get($uri)
      ->addHeaders($headers)
      ->expects(Mime::JSON)
      ->send();

    if ($response->code == self::HTTP_OK) {
      $buckets = array();
      if ($response->body != NULL) {
        $owner = Owner::fromJson($response->body->owner);
        foreach ($response->body->buckets as $key => $value) {
          $buckets[$key] = FDSBucket::fromJson($value);
          $buckets[$key]->setOwner($owner);
        }
      }
      return $buckets;
    } else {
      $message = "List buckets failed for current user, status=" .
        $response->code . ", reason=" . $response->raw_body;
      // TODO(wuzesheng) Write error log
      throw new GalaxyFDSClientException($message);
    }
  }

  public function createBucket($bucket_name) {
    $uri = $this->formatUri($bucket_name);
    $headers = $this->prepareRequestHeader($uri, Http::PUT, Mime::JSON);
    $response = Request::put($uri, "{}")
      ->addHeaders($headers)
      ->send();

    if ($response->code != self::HTTP_OK) {
      $message = "Create bucket failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function deleteBucket($bucket_name) {
    $uri = $this->formatUri($bucket_name);
    $headers = $this->prepareRequestHeader($uri, Http::DELETE, NULL);
    $response = Request::delete($uri)
      ->addHeaders($headers)
      ->send();

    if ($response->code != self::HTTP_OK) {
      $message = "Delete bucket failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function doesBucketExist($bucket_name) {
    $uri = $this->formatUri($bucket_name);
    $headers = $this->prepareRequestHeader($uri, Http::HEAD, NULL);
    $response = Request::head($uri)
      ->addHeaders($headers)
      ->send();

    if ($response->code == self::HTTP_OK) {
      return true;
    } elseif ($response->code == self::HTTP_NOT_FOUND) {
      return false;
    } else {
      $message = "Check bucket existence failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function getBucketAcl($bucket_name) {
    $uri = $this->formatUri($bucket_name, SubResource::ACL);
    $headers = $this->prepareRequestHeader($uri, Http::GET, Mime::JSON);
    $response = Request::get($uri)
      ->expects(Mime::JSON)
      ->addHeaders($headers)
      ->send();

    if ($response->code == self::HTTP_OK) {
      $acp = AccessControlPolicy::fromJson($response->body);
      return $this->acpToAcl($acp);
    } else {
      $message = "Get bucket acl failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function setBucketAcl($bucket_name, $acl) {
    $uri = $this->formatUri($bucket_name, SubResource::ACL);
    $headers = $this->prepareRequestHeader($uri, Http::PUT, Mime::JSON);
    $response = Request::put($uri, json_encode($this->aclToAcp($acl)))
      ->addHeaders($headers)
      ->send();

    if ($response->code != self::HTTP_OK) {
      $message = "Set bucket acl failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function listObjects($bucket_name, $prefix = "") {
    $uri = $this->formatUri($bucket_name, "prefix=" . $prefix,
      "delimiter=" . $this->delimiter);
    $headers = $this->prepareRequestHeader($uri, Http::GET, Mime::JSON);
    $response = Request::get($uri)
      ->addHeaders($headers)
      ->expects(Mime::JSON)
      ->send();

    if ($response->code == self::HTTP_OK) {
      $listing = FDSObjectListing::fromJson($response->body);
      return $listing;
    } else {
      $message = "List objects failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name . ", prefix=" . $prefix .
        ", delimiter=" . $this->delimiter . ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function listNextBatchOfObjects($previous_object_listing) {
    if (!$previous_object_listing->isTruncated()) {
      // TODO(wuzesheng) Log a warning message
      return NULL;
    }

    $bucket_name = $previous_object_listing->getBucketName();
    $prefix = $previous_object_listing->getPrefix();
    $marker = $previous_object_listing->getNextMarker();
    $uri = $this->formatUri($bucket_name, "prefix=" . $prefix,
      "marker=" . $marker);
    $headers = $this->prepareRequestHeader($uri, Http::GET, Mime::JSON);

    $response = Request::get($uri)
      ->addHeaders($headers)
      ->expects(Mime::JSON)
      ->send();

    if ($response->code == self::HTTP_OK) {
      $listing = FDSObjectListing::fromJson($response->body);
      return $listing;
    } else {
      $message = "List next batch of objects failed, status=" . $response->code
        . ", bucket_name=" . $bucket_name . ", prefix=" . $prefix .
        ", marker=" . $marker . ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function putObject($bucket_name, $object_name, $content,
                            $metadata = NULL) {
    $uri = $this->formatUri($bucket_name . "/" . $object_name);
    $header = $this->prepareRequestHeader($uri, Http::PUT,
      self::APPLICATION_OCTET_STREAM, $metadata);

    $response = Request::put($uri, $content)
      ->addHeaders($header)
      ->send();

    if ($response->code == self::HTTP_OK) {
      $result = PutObjectResult::fromJson($response->body);
      return $result;
    } else {
      $message = "Put object failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name . ", object_name=" . $object_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function postObject($bucket_name, $content, $metadata = NULL) {
    $uri = $this->formatUri($bucket_name . "/");
    $header = $this->prepareRequestHeader($uri, Http::POST,
      self::APPLICATION_OCTET_STREAM, $metadata);

    $response = Request::post($uri, $content)
      ->addHeaders($header)
      ->send();

    if ($response->code == self::HTTP_OK) {
      $result = PutObjectResult::fromJson($response->body);
      return $result;
    } else {
      $message = "Post object failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name . ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function getObject($bucket_name, $object_name) {
    $uri = $this->formatUri($bucket_name . "/" . $object_name);
    $headers = $this->prepareRequestHeader($uri, Http::GET, NULL);

    $response = Request::get($uri)
      ->addHeaders($headers)
      ->expects(self::APPLICATION_OCTET_STREAM)
      ->send();

    if ($response->code == self::HTTP_OK) {
      $object = new FDSObject();
      $object->setObjectContent($response->raw_body);

      $summary = new FDSObjectSummary();
      $summary->setBucketName($bucket_name);
      $summary->setObjectName($object_name);
      $summary->setSize($response->headers["Content-Length"]);
      $object->setObjectSummary($summary);
      $object->setObjectMetadata($this->parseObjectMetadataFromHeaders(
        $response->headers->toArray()));
      return $object;
    } else {
      $message = "Get object failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name . ", object_name=" . $object_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function getObjectMetadata($bucket_name, $object_name) {
    $uri = $this->formatUri($bucket_name . "/" . $object_name,
      SubResource::METADATA);
    $headers = $this->prepareRequestHeader($uri, Http::GET, Mime::JSON);

    $response = Request::get($uri)
      ->addHeaders($headers)
      ->send();

    if ($response->code == self::HTTP_OK) {
      $metadata = $this->parseObjectMetadataFromHeaders(
        $response->headers->toArray());
      return $metadata;
    } else {
      $message = "Get object metadata failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name . ", object_name=" . $object_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function getObjectAcl($bucket_name, $object_name) {
    $uri = $this->formatUri($bucket_name . "/" . $object_name,
      SubResource::ACL);
    $headers = $this->prepareRequestHeader($uri, Http::GET, Mime::JSON);

    $response = Request::get($uri)
      ->addHeaders($headers)
      ->expects(Mime::JSON)
      ->send();

    if ($response->code == self::HTTP_OK) {
      $acp = AccessControlPolicy::fromJson($response->body);
      $acl = $this->acpToAcl($acp);
      return $acl;
    } else {
      $message = "Get object acl failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name . ", object_name=" . $object_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function setObjectAcl($bucket_name, $object_name, $acl) {
    $uri = $this->formatUri($bucket_name . "/" . $object_name,
      SubResource::ACL);
    $headers = $this->prepareRequestHeader($uri, Http::PUT, Mime::JSON);

    $response = Request::put($uri, json_encode($this->aclToAcp($acl)))
      ->addHeaders($headers)
      ->send();

    if ($response->code != self::HTTP_OK) {
      $message = "Set object acl failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name . ", object_name=" . $object_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function doesObjectExist($bucket_name, $object_name) {
    $uri = $this->formatUri($bucket_name . "/" . $object_name);
    $headers = $this->prepareRequestHeader($uri, Http::HEAD, Mime::JSON);

    $response = Request::head($uri)
      ->addHeaders($headers)
      ->send();

    if ($response->code == self::HTTP_OK) {
      return true;
    } elseif ($response->code == self::HTTP_NOT_FOUND) {
      return false;
    } else {
      $message = "Check existence of object failed, status=" . $response->code
        . ", bucket_name=" . $bucket_name . ", object_name=" . $object_name
        . ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function deleteObject($bucket_name, $object_name) {
    $uri = $this->formatUri($bucket_name . "/" . $object_name);
    $headers = $this->prepareRequestHeader($uri, Http::DELETE, NULL);

    $response = Request::delete($uri)
      ->addHeaders($headers)
      ->send();

    if ($response->code != self::HTTP_OK) {
      $message = "Delete object failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name . ", object_name=" . $object_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function renameObject($bucket_name, $src_object_name,
                               $dst_object_name) {
    $uri = $this->formatUri($bucket_name . "/" . $src_object_name,
      "renameTo=" . $dst_object_name);
    $headers = $this->prepareRequestHeader($uri, Http::PUT,
      self::APPLICATION_OCTET_STREAM);

    $response = Request::put($uri)
      ->addHeaders($headers)
      ->send();

    if ($response->code != self::HTTP_OK) {
      $message = "Rename object failed, status=" . $response->code .
        ", bucket_name=" . $bucket_name .
        ", src_object_name=" . $src_object_name .
        ", dst_object_name=" . $dst_object_name .
        ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function generatePresignedUri($bucket_name, $object_name, $expiration,
                                       $http_method = "GET") {
    $uri = $this->formatUri($bucket_name . "/" . $object_name,
      Common::GALAXY_ACCESS_KEY_ID . "=" . $this->credential->getGalaxyAccessId(),
      Common::EXPIRES . "=" . $expiration);
    $signature = Signer::signToBase64($http_method, $uri, NULL,
      $this->credential->getGalaxyAccessSecret(), self::SIGN_ALGORITHM);
    $uri .= "&" . Common::SIGNATURE . "=" . $signature;
    return $uri;
  }

  public function getDelimiter() {
    return $this->delimiter;
  }

  public function setDelimiter($delimiter) {
    $this->delimiter = $delimiter;
  }

  private function getCurrentGMTTime() {
    return gmdate(self::DATE_FORMAT, time());
  }

  private function prepareRequestHeader($uri, $http_method, $media_type,
                                        $metadata = null) {
    $headers = array();

    // 1. Format date
    $date = $this->getCurrentGMTTime();
    $headers[Common::DATE] = $date;

    // 2. Set content type
    if ($media_type != NULL && !empty($media_type)) {
      $headers[Common::CONTENT_TYPE] = $media_type;
    }

    if ($metadata != null) {
      foreach ($metadata->getRawMetadata() as $key => $value) {
        $headers[$key] = $value;
      }
    }

    // 3. Set authorization information
    $sign_uri = $uri;
    if (strlen($this->fds_base_uri) > 0) {
      $sign_uri = substr($uri, strlen($this->fds_base_uri) - 1);
    }
    $signature = Signer::signToBase64($http_method, $sign_uri, $headers,
      $this->credential->getGalaxyAccessSecret(), self::SIGN_ALGORITHM);
    $auth_string = "Galaxy-V2 " . $this->credential->getGalaxyAccessId()
      . ":" . $signature;
    $headers[Common::AUTHORIZATION] = $auth_string;
    return $headers;
  }

  private function formatUri() {
    $args_num = func_num_args();
    if ($args_num < 1) {
      throw new GalaxyFDSClientException("Invalid parameters for formatUri()");
    }

    $count = 0;
    $uri = $this->fds_base_uri;
    $args = func_get_args();
    foreach ($args as $arg) {
      if ($count == 0) {
        $uri .= $arg;
      } else if ($count == 1) {
        $uri .= "?" . $arg;
      } else {
        $uri .= "&" . $arg;
      }
      ++$count;
    }
    return $uri;
  }

  private function acpToAcl($acp) {
    if ($acp != NULL) {
        $acl = new AccessControlList();
        foreach ($acp->getAccessControlList() as $key => $value) {
          $grantee_id = $value->grantee->id;
          $permission = $value->permission;
          $grant = new Grant(new Grantee($grantee_id), $permission);
          $acl->addGrant($grant);
        }
        return $acl;
    }
    return NULL;
  }

  private function aclToAcp($acl) {
    if ($acl != NULL) {
      $acp = new AccessControlPolicy();
      $owner = new Owner();
      $owner->setId($this->credential->getGalaxyAccessId());
      $acp->setOwner($owner);

      $access_control_list = $acl->getGrantList();
      $acp->setAccessControlList($access_control_list);
      return $acp;
    }
    return NULL;
  }

  private function parseObjectMetadataFromHeaders($headers) {
    $metadata = new FDSObjectMetadata();
    foreach (FDSObjectMetadata::$PRE_DEFINED_METADATA as $value) {
      if (array_key_exists($value, $headers)) {
        $metadata->addHeader($value, $headers[$value]);
      }
    }

    foreach ($headers as $key => $value) {
      if (Signer::stringStartsWith($key,
        FDSObjectMetadata::USER_DEFINED_METADATA_PREFIX)) {
        $metadata->addUserMetadata($key, $value);
      }
    }
    return $metadata;
  }

  public function getBucketQuota($bucket_name) {
    $uri = $this->formatUri($bucket_name, SubResource::QUOTA);
    $headers = $this->prepareRequestHeader($uri, Http::GET, Mime::JSON);
    $response = Request::get($uri)
        ->expects(Mime::JSON)
        ->addHeaders($headers)
        ->send();

    if ($response->code == self::HTTP_OK) {
      $policy = QuotaPolicy::fromJson($response->body);
      return $policy;
    } else {
      $message = "Get bucket quota failed, status=" . $response->code .
          ", bucket_name=" . $bucket_name .
          ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }

  public function setBucketQuota($bucket_name, $quota) {
    $uri = $this->formatUri($bucket_name, SubResource::QUOTA);
    $headers = $this->prepareRequestHeader($uri, Http::PUT, Mime::JSON);
    $response = Request::put($uri, json_encode($quota))
        ->addHeaders($headers)
        ->send();

    if ($response->code != self::HTTP_OK) {
      $message = "Set bucket quota failed, status=" . $response->code .
          ", bucket_name=" . $bucket_name .
          ", reason=" . $response->raw_body;
      throw new GalaxyFDSClientException($message);
    }
  }
}