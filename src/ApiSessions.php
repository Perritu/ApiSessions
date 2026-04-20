<?php

namespace Perritu\ApiSessions;

/**
 * Class ApiSessions
 *
 * Creates an object to store and retrieve data between requests, based on unique identifiers
 * provided by the user and/or the end client.
 *
 * @package Perritu\ApiSessions
 */
class ApiSessions
{
  /**
   * @var array Instances cache.
   */
  protected static $aInstances = [];

  /**
   * @var stdClass Session data.
   */
  protected $oSession = null;

  /**
   * @var string Session identifier.
   */
  protected $cIdentifier = null;

  /**
   * @var string Instance storage directory.
   */
  protected $cStorageDir = null;

  /**
   * Instance constructor.
   *
   * @param string $cIdentifier Session identifier.
   * @param string $cStorageDir Instance storage directory.
   */
  protected function __construct(string $cIdentifier, string $cStorageDir)
  {
    $this->cIdentifier = $cIdentifier;
    $this->cStorageDir = $cStorageDir;

    $cStorageFile = sprintf(
      '%s/%s/%s.json',
      $this->cStorageDir,
      substr($this->cIdentifier, 0, 2),
      substr($this->cIdentifier, 2)
    );

    if (file_exists($cStorageFile)) {
      $this->oSession = json_decode(file_get_contents($cStorageFile));
    } else {
      $this->oSession = new \stdClass();
    }
  }

  /**
   * Stores session data and performs cleanup.
   *
   * @return void
   */
  public function ____Shutdown(): void
  {
    $cStorageFile = sprintf(
      '%s/%s/%s.json',
      $this->cStorageDir,
      substr($this->cIdentifier, 0, 2),
      substr($this->cIdentifier, 2)
    );

    if (!is_dir(dirname($cStorageFile))) mkdir(dirname($cStorageFile), 0700, true);
    file_put_contents($cStorageFile, json_encode($this->oSession));

    if (random_int(0, 100) >= 15) return;

    $uExpires = time() - 600; // 10 minutes
    $fnWalker = function ($cDir) use (&$fnWalker, $uExpires) {
      foreach (glob(sprintf('%s/*', $cDir)) as $cFile)
        if (is_dir($cFile)) $fnWalker($cFile);
        else if (filemtime($cFile) < $uExpires) unlink($cFile);
    };
    $fnWalker($this->cStorageDir);
  }

  /**
   * Returns an instance of ApiSessions.
   *
   * @param string $cIdentity String to be hashed and used as identifier.
   * @param string|null $cStorageDir Directory to use as storage. Defaults to /tmp.
   *
   * @return ApiSessions
   */
  public static function &Instance(string $cIdentity, ?string $cStorageDir = null): ApiSessions
  {
    if ($cStorageDir === null)
      $cStorageDir = '/tmp';

    if (!is_dir($cStorageDir) || !is_writable($cStorageDir))
      throw new \Exception(sprintf('Storage directory "%s" does not exist or is not writable.', $cStorageDir));

    $cHash = hash('gost', $cIdentity);

    if (isset(self::$aInstances[$cHash]))
      return self::$aInstances[$cHash];

    $oInstance = new static($cHash, $cStorageDir);
    self::$aInstances[$cHash] = $oInstance;

    register_shutdown_function([$oInstance, '____Shutdown']);

    return $oInstance;
  }
}
