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
   * @var \stdClass Session data.
   */
  protected ?\stdClass $oSession = null;

  /**
   * @var string Session identifier.
   */
  protected $cIdentifier = null;

  /**
   * @var string Instance storage directory.
   */
  protected $cStorageDir = null;

  /**
   * @var int Time in seconds until the session expires.
   */
  protected $uExpires = 600;

  /**
   * @var bool Flag to prevent duplicate GC calls.
   */
  protected $bGarbageCollect = false;

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
      $cPayload = file_get_contents($cStorageFile);
      if ($cPayload === false) {
        throw new \RuntimeException(sprintf('Unable to read session file "%s".', $cStorageFile));
      }

      $oSession = json_decode($cPayload, false, 512, JSON_THROW_ON_ERROR);
      if (!$oSession instanceof \stdClass) {
        throw new \RuntimeException(sprintf('Session file "%s" must contain a JSON object.', $cStorageFile));
      }

      $this->oSession = $oSession;
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

    $cStoragePath = dirname($cStorageFile);
    if (!is_dir($cStoragePath) && !mkdir($cStoragePath, 0700, true))
      throw new \RuntimeException(sprintf('Unable to create session directory "%s".', $cStoragePath));

    if (!is_writable($cStoragePath))
      throw new \RuntimeException(sprintf('Session directory "%s" is not writable.', $cStoragePath));

    $cPayload = json_encode($this->oSession, JSON_THROW_ON_ERROR);
    if (!($cTempFile = tempnam($cStoragePath, basename($cStorageFile) . '.')))
      throw new \RuntimeException(sprintf('Unable to create temporary session file in "%s".', $cStoragePath));

    try {
      if (file_put_contents($cTempFile, $cPayload, LOCK_EX) === false)
        throw new \RuntimeException(sprintf('Unable to write temporary session file "%s".', $cTempFile));

      chmod($cTempFile, 0600);
      if (!rename($cTempFile, $cStorageFile))
        throw new \RuntimeException(sprintf('Unable to replace session file "%s".', $cStorageFile));
    } finally {
      is_file($cTempFile) && unlink($cTempFile);
    }

    if (random_int(0, 100) <= 15) $this->____GarbageCollect();
  }

  /**
   * Perform the storage cleanup.
   *
   * @return void
   */
  protected function ____GarbageCollect(): void
  {
    if ($this->bGarbageCollect) return;
    $this->bGarbageCollect = true;

    $uExpires = time() - $this->uExpires;
    foreach (new \DirectoryIterator($this->cStorageDir) as $oDir) {
      if ($oDir->isDot() || !$oDir->isDir() || $oDir->isLink()) continue;
      if (!preg_match('/^[a-f0-9]{2}$/', $oDir->getFilename())) continue;

      foreach (new \DirectoryIterator($oDir->getPathname()) as $oFile) {
        if ($oFile->isDot() || !$oFile->isFile() || $oFile->isLink()) continue;
        if ($oFile->getExtension() !== 'json') continue;
        if ($oFile->getMTime() < $uExpires) unlink($oFile->getPathname());
      }
    }
  }

  /**
   * Returns an instance of ApiSessions.
   *
   * @param string $cIdentity String to be hashed and used as identifier.
   * @param string|null $cStorageDir Directory to use as storage. Defaults to system temporary directory.
   *
   * @return ApiSessions
   */
  public static function &Instance(string $cIdentity, ?string $cStorageDir = null): ApiSessions
  {
    if ($cStorageDir === null)
      $cStorageDir = sys_get_temp_dir() . '/apisessions';

    if (!is_dir($cStorageDir) && !mkdir($cStorageDir, 0700, true))
      throw new \Exception(sprintf('Storage directory "%s" does not exist or is not writable.', $cStorageDir));

    if (!is_writable($cStorageDir))
      throw new \Exception(sprintf('Storage directory "%s" is not writable.', $cStorageDir));

    $cHash = hash('gost', $cIdentity);

    if (isset(self::$aInstances[$cHash]))
      return self::$aInstances[$cHash];

    $oInstance = new self($cHash, $cStorageDir);
    self::$aInstances[$cHash] = $oInstance;

    register_shutdown_function([$oInstance, '____Shutdown']);

    return $oInstance;
  }

  /**
   * Magic method to access session data.
   *
   * @param string $cName Data name.
   * @return mixed
   */
  public function __get(string $cName): mixed
  {
    return $this->oSession->{$cName} ?? null;
  }

  /**
   * Magic method to set session data.
   *
   * @param string $cName Data name.
   * @param mixed $mValue Data value.
   * @return void
   */
  public function __set(string $cName, mixed $mValue): void
  {
    $this->oSession->{$cName} = $mValue;
  }

  /**
   * Magic method isset to check if session data exists.
   *
   * @param string $cName Data name.
   * @return bool
   */
  public function __isset(string $cName): bool
  {
    return isset($this->oSession->{$cName});
  }

  /**
   * Magic method unset to remove session data.
   *
   * @param string $cName Data name.
   * @return void
   */
  public function __unset(string $cName): void
  {
    unset($this->oSession->{$cName});
  }
}
