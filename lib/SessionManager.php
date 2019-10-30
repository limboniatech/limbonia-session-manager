<?php
namespace Limbonia;

/**
 * Limbonia session manager
 *
 * Contains all the code needed to control the session
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia
 */
class SessionManager
{
  /**
   * The default session cookie name
   *
   * @var string
   */
  protected static $sDefaultSessionName = 'Limbonia_SessionKey';

  /**
   * The number of secondes to let the obsolete session continue to live.
   *
   * @var integer
   */
  protected static $iObsoleteTime = 10;

  /**
   * The current session cookie name, if thre is one...
   *
   * @var string
   */
  protected static $sSessionName = '';

  /**
   * Has the session been started
   *
   * @var unknown
   */
  protected static $bStarted = false;

  /**
   * Has the session been started?
   *
   * @return boolean
   */
  public static function isStarted()
  {
    return self::$bStarted;
  }

  /**
   * Start a new session that is that isn't old and expired and is locked to the current
   * user's IP address and their browser's user agent string.
   */
  public static function start()
  {
    if (!self::$bStarted)
    {
      \session_name(self::sessionName());
      self::$bStarted = \session_start();

      if (self::isValid())
      {
        if (!self::isLocked())
        {
          $_SESSION = [];
          self::lock();
          self::regenerate();
        }
      }
      else
      {
        $_SESSION = [];
        \session_destroy();
        \session_start();
        self::lock();
      }
    }
  }

  /**
   * Regenerate the session ID
   *
   * NOTE: The old session is left for a few seconds so that any
   * remaining web-calls that are already in progress don't have
   * their session data ruined by racing against this function.
   */
  public static function regenerate()
  {
    //If this session is marked OBSOLETE then the following code still needs to run...
    if (empty($_SESSION['OBSOLETE']))
    {
      //Set current session to expire in a few seconds
      $_SESSION['OBSOLETE'] = time() + self::$iObsoleteTime;

      //leave the old session intact, for now
      \session_regenerate_id(false);

      //Get the newly generated session ID and close both sessions to allow other scripts to use them
      $sNewSession = \session_id();
      \session_write_close();

      //Set session to the new ID and start it up again
      \session_id($sNewSession);
      \session_start();

      //Remove the obsolete field from the current session, so it can be used as expected
      unset($_SESSION['OBSOLETE']);
    }
  }

  /**
   * Return the session name string or set the current sesison name
   *
   * @param string $sSessionName
   */
  public static function sessionName($sSessionName = '')
  {
    if (empty($sSessionName))
    {
      return empty(self::$sSessionName) ? self::$sDefaultSessionName : self::$sSessionName;
    }

    self::$sSessionName = $sSessionName;
  }

  /**
   * Return the agent string.  If the call originates from a browser return the browser's user_agent.
   * But if the call originated from a server then it must have been a web-call so return that.
   *
   * @return string
   */
  public static function userAgent()
  {
    $sUserAgent = filter_input(INPUT_SERVER, 'HTTP_USER_AGENT');

    if (!empty($sUserAgent))
    {
      return $sUserAgent;
    }

    if (Util::isCLI())
    {
      //then this is a local server call running from command line
      return 'Server_CLI';
    }

    //then we must be making a server-to-server web-call
    return 'Server_Web';
  }

  /**
   * Get the IP Address to fix the session to
   *
   * @return string
   */
  public static function address()
  {
    if (!Util::isCLI())
    {
      //otherwise return the remote address
      $sAddress = filter_input(INPUT_SERVER, 'REMOTE_ADDR');

      if (!empty($sAddress))
      {
        return $sAddress;
      }
    }

    //this is a local server call running from command line so return the server address
    return \getHostByName(getHostName());
  }

  /**
   * Lock the session to the address and user agent
   */
  public static function lock()
  {
    $_SESSION['ADDRESS'] = self::address();
    $_SESSION['USERAGENT'] = self::userAgent();
  }

  /**
   * Check that the items the session are locked to are set and still correct.
   *
   * NOTE: Currently we are locking the session to the user's IP address and browser agent
   *
   * @return boolean
   */
  protected static function isLocked()
  {
    //make sue the user's stored IP address matches the one the server is seeing
    if (!isset($_SESSION['ADDRESS']) || $_SESSION['ADDRESS'] != self::address())
    {
      return false;
    }

    //make sure the stored user agent matches the one the server is seeing
    if(!isset($_SESSION['USERAGENT']) || $_SESSION['USERAGENT'] != self::userAgent())
    {
      return false;
    }

    //Yay, everything checks out!
    return true;
  }

  /**
   * Make sure the current session should still
   *
   * NOTE: Currently we are only checking the session for expiration and nothing else.
   *
   * @return boolean
   */
  protected static function isValid()
  {
    //if obsolete is not set or it hasn't expired yet then the session is valid
    return self::$bStarted && (!isset($_SESSION['OBSOLETE']) || $_SESSION['OBSOLETE'] >= time());
  }
}