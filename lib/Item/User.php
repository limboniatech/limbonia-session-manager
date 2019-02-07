<?php
namespace Limbonia\Item;

/**
 * Limbonia User Item Class
 *
 * Item based wrapper around the User table
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia
 */
class User extends \Limbonia\Item
{
  /**
   * The default password length
   */
  const PASSWORD_DEFAULT_LENGTH = 16;

  /**
   * The "cost" for the encryption function to  use
   */
  const PASSWORD_ENCRYPTION_COST = 10;

  /**
   * The name of the algorithm used to encrypt the password
   */
  const PASSWORD_ENCRYPTION_ALGO = PASSWORD_BCRYPT;

  /**
   * List of resources that this user has access to
   *
   * @var array
   */
  protected $hResource = null;

  /**
   * Is this user an admin?
   *
   * @var boolean
   */
  protected $bAdmin = false;

  /**
   * Generate and return a user object from the specified email
   *
   * @param string $sEmail
   * @param \Limbonia\Database $oDatabase (optional)
   * @return \Limbonia\Item\User User object on success or false on failure
   */
  public static function getByEmail($sEmail, \Limbonia\Database $oDatabase = null)
  {
    $oUserList = parent::search('User', ['Email' => $sEmail], null, $oDatabase);

    if (count($oUserList) == 0)
    {
      throw new \Exception("Unkown user: $sEmail");
    }

    return $oUserList[0];
  }

  /**
   * Generate and return a user object from the specified auth_token
   *
   * @param string $sAuthToken
   * @param \Limbonia\Database $oDatabase (optional)
   * @return \Limbonia\Item\User
   * @throws \Limbonia\Exception\Web
   */
  public static function getByAuthToken($sAuthToken, \Limbonia\Database $oDatabase = null)
  {
    $oDatabase = $oDatabase instanceof \Limbonia\Database ? $oDatabase : \Limbonia\Controller::getDefault()->getDB();
    $oDatabase->query("DELETE FROM UserAuth WHERE TIMEDIFF(NOW(), LastUseTime) > '00:60:00'");
    $oResult = $oDatabase->query("SELECT * FROM UserAuth WHERE AuthToken = :AuthToken AND TIMEDIFF(NOW(), LastUseTime) < '00:20:00'", ['AuthToken' => $sAuthToken]);
    $hRow = $oResult->fetchOne();

    if (empty($hRow))
    {
      throw new \Limbonia\Exception\Web('Valid auth_token not found', null, 401);
    }

    $oUser = \Limbonia\Item::fromId('User', $hRow['UserID'], $oDatabase);

    if (!$oUser->active)
    {
      throw new \Limbonia\Exception\Web('User not active', null, 401);
    }

    $oDatabase->query("UPDATE UserAuth SET LastUseTime = NOW() WHERE AuthToken = '{$hRow['AuthToken']}' AND UserID = {$hRow['UserID']}");
    return $oUser;
  }

  /**
   * Generate and return a user object from the specified api_key
   *
   * @param string $sApiKey
   * @param \Limbonia\Database $oDatabase (optional)
   * @return \Limbonia\Item\User
   * @throws \Limbonia\Exception\Web
   */
  public static function getByApiKey($sApiKey, \Limbonia\Database $oDatabase = null)
  {
    $oDatabase = $oDatabase instanceof \Limbonia\Database ? $oDatabase : \Limbonia\Controller::getDefault()->getDB();
    $oUserList = parent::search('User', ['ApiKey' => $sApiKey], null, $oDatabase);

    if (count($oUserList) == 0)
    {
      throw new \Limbonia\Exception\Web("Unkown user: $sApiKey", null, 401);
    }

    return $oUserList[0];
  }

  /**
   * Make sure the specified password follows all the current guidelines
   *
   * @todo Create method for adding / controlling the password guidelines with config and scripting options
   *
   * @param string $sPassword
   * @throws \Exception
   */
  public static function validatePassword($sPassword)
  {
    if (empty($sPassword))
    {
      throw new \Exception('Empty password.');
    }
  }

  /**
   * Generate and return and password of the specified length
   *
   * @param integer $iLength (optional)
   * @return string
   */
  public static function generatePassword($iLength = self::PASSWORD_DEFAULT_LENGTH)
  {
    $iLength = empty($iLength) ? self::PASSWORD_DEFAULT_LENGTH : (integer)$iLength;
    $sLetters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $sPassword = '';

    for ($i = 0; $i < $iLength; $i++)
    {
      $sPassword .= $sLetters[(rand(1, strlen($sLetters) - 1))];
    }

    return $sPassword;
  }

  /**
   * Generate an auth_token, add it to the database for this user and then return it
   *
   * @return string
   * @throws \Limbonia\Exception
   */
  public function generateAuthToken()
  {
    $sAuthToken = sha1(self::generatePassword());
    $oResult = $this->getDatabase()->prepare("INSERT INTO UserAuth (UserID, AuthToken, LastUseTime) VALUES (:UserID, :AuthToken, NOW())");

    if (!$oResult->execute(['UserID' => $this->id, 'AuthToken' => $sAuthToken]))
    {
      throw new \Limbonia\Exception('Failed to store auth_token');
    }

    return $sAuthToken;
  }

  /**
   * Delete the specified auth_token from this user
   *
   * @param type $sAuthToken
   * @return boolean
   * @throws \Limbonia\Exception
   */
  public function deleteAuthToken($sAuthToken)
  {
    $oResult = $this->getDatabase()->prepare("DELETE FROM UserAuth WHERE UserID = :UserID AND AuthToken = :AuthToken");

    if (!$oResult->execute(['UserID' => $this->id, 'AuthToken' => $sAuthToken]))
    {
      throw new \Limbonia\Exception('Failed to delete auth_token');
    }

    if ($oResult->rowCount() == 0)
    {
      throw new \Limbonia\Exception("Failed to delete auth_token: $sAuthToken");
    }

    return true;
  }

  /**
   * Authenticate the current user using what ever method they require
   *
   * @param string $sPassword
   * @throws \Exception
   */
  public function authenticate(string $sPassword)
  {
    if (!$this->active)
    {
      throw new \Limbonia\Exception\Web('User not active', null, 401);
    }

    if (!password_verify($sPassword, $this->password))
    {
      throw new \Limbonia\Exception\Web('Invalid password', null, 401);
    }
  }

  /**
   * Reset this user's password to something random and return that password
   *
   * @return string
   */
  public function resetPassword()
  {
    $sPassword = self::generatePassword();
    $this->password = $sPassword;
    $this->save();
    return $sPassword;
  }

  /**
   * Generate and return the list of resources that this user has access to
   */
  protected function generateResourceList()
  {
    if (!is_null($this->hResource) || $this->bAdmin)
    {
      return true;
    }

    $oResult = $this->getDatabase()->prepare("SELECT COUNT(1) FROM User_Role u_r NATURAL JOIN Role_Key r_k NATURAL JOIN ResourceKey rk WHERE rk.Name='Admin' AND r_k.Level = 1000 AND u_r.UserID = :UserID");
    $oResult->execute([':UserID' => $this->hData['UserID']]);
    $iAdminCount = $oResult->fetchColumn();
    $this->bAdmin = $iAdminCount > 0;

    if ($this->bAdmin)
    {
      $this->hResource = null;
    }
    else
    {
      $oResult = $this->getDatabase()->prepare("SELECT rl.Resource, rl.Component, rk.Name, r_k.Level FROM ResourceLock rl, Role_Key r_k, ResourceKey rk, User_Role u_r WHERE rk.KeyID = r_k.KeyID AND (rl.KeyID = r_k.KeyID OR rk.Name = 'Admin') AND rl.MinKey <= r_k.Level AND r_k.RoleID =u_r.RoleID AND  u_r.UserID = :UserID");
      $bSuccess = $oResult->execute([':UserID' => $this->hData['UserID']]);
      $this->hResource = [];

      if ($bSuccess && count($oResult) > 0)
      {
        foreach ($oResult as $hResource)
        {
          $this->hResource[$hResource['Resource']][] = $hResource['Component'];
        }
      }
    }
  }

  /**
   * Is this user an admin?
   *
   * @return boolean
   */
  public function isAdmin()
  {
    $this->generateResourceList();
    return $this->bAdmin;
  }

  /**
   * Does this user have the specified resource?
   *
   * @param string $sResource
   * @param string $sComponent (optional)
   * @return boolean
   */
  public function hasResource($sResource, $sComponent = null)
  {
    $this->generateResourceList();

    if ($this->isAdmin())
    {
      return true;
    }

    if (empty($sComponent))
    {
      return isset($this->hResource[$sResource]);
    }

    return isset($this->hResource[$sResource]) && in_array($sComponent, $this->hResource[$sResource]);
  }

  /**
   * Return the list of resource keys and their levels that this role has
   *
   * @return array
   */
  public function getRoles()
  {
    return parent::getList('Role', "SELECT r.* FROM Role r NATURAL JOIN User_Role u_r WHERE u_r.UserID = $this->id ORDER BY NAME", $this->getDatabase());
  }

  /**
   * Return the list of resource key objects
   *
   * @return \Limbonia\ItemList
   */
  public function getRoleList()
  {
    return parent::search('Role', null, 'Name', $this->getDatabase());
  }

  /**
   * Set the specified list of resource keys for this role
   *
   * @param array $aRole
   */
  public function setRoles($aRole)
  {
    $this->getDatabase()->exec('DELETE FROM User_Role WHERE UserID = ' . $this->id);

    if (count($aRole) > 0)
    {
      $oResult = $this->getDatabase()->prepare("INSERT INTO User_Role VALUES ($this->id, :Role)");

      foreach ($aRole as $iRole)
      {
        $oResult->execute([':Role' => $iRole]);
      }
    }
  }

  /**
   * Format the specified value to valid input using type data from the specified column
   *
   * @param string $sName
   * @param mixed $xValue
   * @return mixed
   */
  protected function formatInput($sName, $xValue)
  {
    if ($sName == 'Password')
    {
      return password_hash($xValue, self::PASSWORD_ENCRYPTION_ALGO, ['cost' => self::PASSWORD_ENCRYPTION_COST]);
    }

    if ($sName == 'Email')
    {
      $xValue = trim($xValue);
      //if it validates successfully then let the normal value be returned...
      \Limbonia\Email::validate($xValue);
    }

    return parent::formatInput($sName, $xValue);
  }

  /**
   * Get the specified data
   *
   * @param string $sName
   * @return mixed
   */
  public function __get($sName)
  {
    if (strtolower($sName) == 'name')
    {
      return trim("$this->firstName $this->lastName");
    }

    if (strtolower($sName) == 'iscontact')
    {
      return 'contact' == parent::__get('type');
    }

    return parent::__get($sName);
  }

  /**
   * Determine if the specified value is set (exists) or not...
   *
   * @param string $sName
   * @return boolean
   */
  public function __isset($sName)
  {
    if (strtolower($sName) == 'name')
    {
      return true;
    }

    return parent::__isset($sName);
  }

  /**
   * Return the list of open tickets owned by this user
   *
   * @return \Limbonia\ItemList
   */
  public function getTickets()
  {
    return parent::search('Ticket', ['OwnerID' => $this->id, 'Status' => '!=:closed'], ['Priority', 'DueDate DESC'], $this->getDatabase());
  }

  /**
   *  Is this user a contact?
   *
   * @return boolean
   */
  public function isContact()
  {
    return 'contact' === $this->type;
  }

  /**
   * Is this user allowed to access the specified ticket?
   *
   * @param integer $iTicket - ID of the ticket to check
   * @return boolean
   * @throws \Exception
   */
  public function canAccessTicket($iTicket)
  {
    if (!$this->isContact())
    {
      return true;
    }

    $oResult = $this->getController()->getDB()->prepare("SELECT COUNT(1) FROM Ticket WHERE TicketID = :TicketID AND (OwnerID = $this->id OR CreatorID = $this->id)");
    $oResult->bindValue(':TicketID', $iTicket, \PDO::PARAM_INT);

    if (!$oResult->execute())
    {
      $aError = $oResult->errorInfo();
      throw new \Exception("Failed to load data from $this->sTable: {$aError[2]}");
    }

    return $oResult->fetch() > 0;
  }
}