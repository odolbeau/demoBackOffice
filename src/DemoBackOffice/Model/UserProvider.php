<?php
namespace DemoBackOffice\Model{

	use Silex\Application;
	use Symfony\Component\Security\Core\User\UserProviderInterface;
	use Symfony\Component\Security\Core\User\UserInterface;
	use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
	use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
	use DemoBackOffice\Model\Entity\UserType;
	use DemoBackOffice\Model\Entity\User;
	use Exception;

	/**
	 * Handle user db request
	 * implements UserProviderInterface for handle connection for the security.firewall
	 */
	class UserProvider implements UserProviderInterface{

		protected $app;

		public function __construct(Application $app){
			$this->app = $app;
		}

		/**
		 * delete User
		 * @param User user
		 */
		public function deleteUser(User $user){
			if($user->isSuperAdmin()) throw new Exception('You cannot delete root account');
			$stmt = $this->app['db']->executeQuery("delete from user where id_user=?", array( $user->id ));
		}

		/**
		 * search user
		 * @param	string	by
		 * @param	string	value
		 * @return User
		 */
		protected function searchUser($by, $value){
			$sql = 'SELECT id_user, login_user, password_user, id_type_user, date_modification_user from user where ';
			if($by == "id") $sql .= " id_user=?";
			else $sql .= " login_user=?";
			$stmt = $this->app['db']->executeQuery( $sql, array($value));
			if (!$user = $stmt->fetch()) return new User("", "", "", null, "" );
			$userType = $this->app['manager.rights']->getUserTypeById($user['id_type_user']);
			return new User($user['id_user'], $user['login_user'], $user['password_user'], $userType, $user['date_modification_user']);
		}

		/**
		 * get User by id
		 * @param	string	id
		 * @return	User
		 */
		public function getUserById($id){
			return $this->searchUser('id', $id);
		}

		/**
		 * get User by name
		 * @param	string	name
		 * @return	User
		 */
		public function getUserByName($name){
			return $this->searchUser('name', $name);
		}

		/**
		 * change user password (for admin)
		 */
		public function changePassword($id, $password){
			$user = $this->getUserById($id);
			if($user->id == $id){
				$user->password = $password;
				$this->app['db']->executeQuery("update user set password_user = ? where id_user=?", array($user->id, $user->password));
				return $user;
			}else throw new Exception('unfound user');
		}

		/**
		 * save user
		 * @param	string	id
		 * @param	string	login
		 * @param	string	password
		 * @param	string	userType
		 * @param	bool	new
		 * @return	User
		 */
		public function saveUser($id, $login, $password, $userType, $new = false){
			$user = $this->getUserByName($login);
			$user->update = date('Y-m-d H:i:s');
			$params = array($login, $password, $user->update, $userType );
			if($user->id != "" || $id != ""){
				if($new || ($user->id != "" && $user->id != $id )) throw new Exception('Username already used');
				if($user->isSuperAdmin()) throw new Exception('This is super admin user, it cannot be updated');
				$sql = "update user set login_user=?, password_user=?, date_modification_user=?, id_type_user=? where id_user=?";
				$params[] = $id;
			}else{
				array_unshift( $params, $user->update);
				$sql = "insert into user(date_creation_user, login_user, password_user, date_modification_user, id_type_user) VALUES(?,?,?,?,?)";
			}
			$this->app['db']->executeQuery($sql, $params);
			return $this->getUserByName($login);
		}

		/**
		 *	return a type_user list
		 *	@return	array(<User>)
		 */
		public function loadUsers(){
			$stmt = $this->app['db']->executequery('SELECT id_user, login_user, password_user, id_type_user, date_modification_user from user');
			if (!$users = $stmt->fetchall()) return array(); 
			for ($i = 0; $i < count($users); $i++) {
				$user = $users[$i];
				$userType = $this->app['manager.rights']->getUserTypeById($user['id_type_user']);
				$users[$i] = new User($user['id_user'], $user['login_user'], $user['password_user'], $userType, $user['date_modification_user']);
			}
			return $users;
		}

		/**
		 * load all userType access return all right access
		 * @param Usertype
		 * @return Usertype
		 */
		protected function loadUserTypeAccess(UserType $userType){
			$userType->purgeAccess();
			if($userType->id > 0){
				$accessType = $this->app['manager.rights']->getUserTypeAccess($userType);
				for ($i = 0; $i < count($accessType); $i++) {
					$userType->addAccess($accessType[$i]['id_section'], new AccessType($accessType[$i]['id_type_access']));
				}
			}
			return $userType;
		}

		/** UserProviderInterface **/
		/** interface user by security.firewall to connect a user **/
		public function loadUserByUsername($username){
			$user =  $this->searchUser('name', $username);
			if ($user->id == null) {
				throw new UsernameNotFoundException(sprintf('Username "%s" does not exist.', $username));
			}
			$user->loadSections($this->app['manager.section']->loadSections());
			return $user;
		}

		function refreshUser(UserInterface $user) {
			if (!$user instanceof User) {
				throw new UnsupportedUserException(sprintf('Instance of "%s" are not supported'), get_class($user));
			}
			return $this->loadUserByUsername($user->getUsername());
		}

		function supportsClass($class) {
			//return $class === 'Symfony\Component\Security\Core\User\User';
			return $class === 'DemoBackOffice\Model\Entity\User';
		}

	}

}
