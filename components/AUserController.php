<?php
/**
 * Abstract controller class to deal with user related functions such as registering, recovering passwords etc.
 *
 *
 *
 * @author Charles Pick
 * @package packages.users.controllers
 */
abstract class AUserController extends Controller {
	/**
	 * Declares class based actions.
	 * @return array the class based action configuration
	 */
	public function actions() {
		return array(
			"login" => array(
				"class" => "packages.users.components.ALoginAction"
			),
			"autoComplete" => array(
				"class" => "packages.autocomplete.AAutoCompleteAction",
				"modelClass" => Yii::app()->getModule("users")->userModelClass,
				"attributes" => array("name"),
				"displayAttributes" => array("id", "name", "thumbnail.url")
			),
		);
	}
	/**
	 * Registers a new user
	 */
	public function actionRegister() {
		$usersModule = Yii::app()->getModule("users");
	 	$modelClass = $usersModule->userModelClass;
		$model = new $modelClass("register");
		$this->performAjaxValidation($model);
		if (isset($_POST[$modelClass])) {

			$model->attributes = $_POST[$modelClass];
			if ($model->save()) {

				$this->redirect($usersModule->redirectAfterRegistration);
			}
		}
		if (Yii::app()->request->isAjaxRequest) {
			$this->renderPartial("register",array("model" => $model),false,true);
		}
		else {
			$this->render("register",array("model" => $model));
		}
	}

	/**
	 * The user's account page
	 */
	public function actionAccount() {
		if (Yii::app()->user->isGuest) {
			Yii::app()->user->loginRequired(); // access rules should be used to prevent this occurring
		}
		$model = Yii::app()->user->model;
		if (Yii::app()->request->isAjaxRequest) {
			$this->renderPartial("account",array("model" => $model),false,true);
		}
		else {
			$this->render("account",array("model" => $model));
		}
	}

	/**
	 * Allows a logged in user to change their password
	 */
	public function actionChangePassword() {
		$model = Yii::app()->user->getModel(); /* @var AUser $model */
		$modelClass = get_class($model);
		$model->setScenario("newPassword");
		if (isset($_POST[$modelClass])) {
			$model->attributes = $_POST[$modelClass];
			$model->requiresNewPassword = false;
			if ($model->save()) {
				Yii::log('['.$model->id.'] Password changed.','passwordChanged','user.activity');
				$this->redirect(array("account"));
			}
		}
		$model->password = "";
		$this->render("newPassword",array("model" => $model));
	}
	/**
	 * Allows a user to reset their password if they've forgotten it.
	 * The user enters their email address and we send them a link with
	 * a unique key. When they click this link they're presented with
	 * a form to reset their password. After reseting their password successfully
	 * we log them in and redirect them to their account page.
	 * @param integer $id The id of this user
	 * @param string $key The unique key for this user
	 */
	public function actionResetPassword($id = null, $key = null) {
		$usersModule = Yii::app()->getModule("users"); /* @var AUsersModule $usersModule */
		$modelClass = $usersModule->userModelClass;
		if ($id !== null && $key !== null) {
			// check if the id + key match for this user
			$user = $modelClass::model()->findByPk($id); /* @var AUser|APasswordBehavior $user */
			if (!is_object($user)) {
				Yii::log("Invalid password reset attempt (no such user)","invalidPasswordReset","user.activity");
				throw new CHttpException(400,"Your request is invalid");
			}
			elseif($user->getPasswordResetCode() != $key) {
				Yii::log("[$user->id] Invalid password reset attempt (invalid code)","invalidPasswordReset","user.activity");
				throw new CHttpException(400,"Your request is invalid");
			}
			// now the user needs to change their password
			$user->scenario = "newPassword";
			if (isset($_POST[$modelClass])) {
				$user->attributes = $_POST[$modelClass];
				if ($user->save()) {
					Yii::log("[$user->id] Password reset via email","passwordReset","user.activity");
					$identityClass = $usersModule->identityClass;
					$identity = new $identityClass($user->email); /* @var CUserIdentity $identity */
					$identity->id = $user->id;
					$identity->name = $user->name;
					if ($usersModule->autoLoginByDefault) {
						Yii::app()->user->login($identity,$usersModule->autoLoginDuration);
					}
					else {
						Yii::app()->user->login($identity,0);
					}
					Yii::app()->user->setFlash("success","<h2>Your password was changed</h2>");
					$this->redirect(array("/users/user/account"));
				}
			}
			$user->password = "";
			$this->render("newPassword",array("model" => $user));

			return;
		}

		$model = new $modelClass("resetPassword"); /* @var AUser $model */
		if (isset($_POST[$modelClass])) {
			$user = $modelClass::model()->findByAttributes(array("email" => $_POST[$modelClass]['email'])); /* @var AUser $user */
			if (is_object($user)) {
				// send the user a password reset email

				$email = Yii::createComponent("packages.email.models.AEmail"); /* @var AEmail $email */
				$email->sender = Yii::app()->email->getDefaultSender();
				$email->isHtml = true;
				$email->recipient = $user->email;
				$email->viewData = array("user" => $user);
				$email->view = "/user/emails/resetPassword";

				if ($email->send()) {
					Yii::app()->user->setFlash("success",$this->renderPartial("flashMessages/resetEmailSent",array("user" => $user),true));
					$this->redirect(Yii::app()->user->loginUrl);
				}
				else {
					$model->addError("email", "There was a problem sending email to this address");
				}
			}
			else {
				// TODO: Decide whether this information leak (the user doesn't exist) is worth it or not
				$model->addError("email","We couldn't find a user with that email address");
			}
		}
		if (Yii::app()->request->isAjaxRequest) {
			$this->renderPartial("resetPassword",array("model" => $model),false,true);
		}
		else {
			$this->render("resetPassword",array("model" => $model));
		}
	}

	/**
	 * Activates the user's account.
	 * This is used when AUserModule.requireActivation is true.
	 * After the code has been verified, the user's account will be activated and the
	 * user will be logged in and taken to their account page.
	 * @param integer $id The user's id
	 * @param string $key The unique activation code for this user
	 */
	public function actionActivate($id,$key) {
		$usersModule = Yii::app()->getModule("users"); /* @var AUsersModule $usersModule */
		$modelClass = $usersModule->userModelClass;
		// check if the id + key match for this user
		$user = $modelClass::model()->findByPk($id); /* @var AUser|APasswordBehavior $user */
		if (!is_object($user)) {
			Yii::log("Invalid account activation attempt (no such user)","warning","user.activity.activateAccount");
			throw new CHttpException(400,"Your request is invalid");
		}
		elseif($user->getActivationCode() != $key) {
			Yii::log("[$user->id] Invalid account activation attempt (invalid code)","warning","user.activity.activateAccount");
			throw new CHttpException(400,"Your request is invalid");
		}
		elseif($user->isActive) {
			Yii::log("[$user->id] Invalid account activation attempt (already active)","warning","user.activity.activateAccount");
			Yii::app()->user->setFlash("info","<h2>You account is already active</h2><p>Your account has already been activated, please login to continue</p>");
			$this->redirect(Yii::app()->user->loginUrl);
			return;
		}
		if (!$user->activate()) {
			Yii::app()->user->setFlash("error","<h2>There was a problem activating your account</h2>");
			$this->redirect(array("/site/index"));
		}
		// now we need to log this user in
		$identityClass = $usersModule->identityClass;
		$identity = new $identityClass($user->email);
		$identity->id = $user->id;
		$identity->name = $user->name;
		if ($usersModule->autoLoginByDefault) {
			Yii::app()->user->login($identity,$usersModule->autoLoginDuration);
		}
		else {
			Yii::app()->user->login($identity,0);
		}
		Yii::app()->user->setFlash("success","<h2>Your account has been activated successfully!</h2>");
		$this->redirect(array("/users/user/account"));
	}

	/**
	 * Displays an interface for editing the user's settings
	 */
	public function actionSettings() {
		if (Yii::app()->user->isGuest) {
			Yii::app()->user->loginRequired(); // access rules should be used to prevent this occurring
		}
		$model = Yii::app()->user->model; /* @var AUser $model */
		$modelClass = get_class($model);
		$this->performAjaxValidation($model);
		if (isset($_POST[$modelClass])) {
			if (isset($_POST[$modelClass]['preferences'])) {
				$preferences = $_POST[$modelClass]['preferences'];
				unset($_POST[$modelClass]['preferences']);
			}
			else {
				$preferences = array();
			}
			$model->setAttributes($_POST[$modelClass]);
			if ($model->save()) {
				foreach($preferences as $name => $value) {
					$model->setPreference($name,$value);
				}
			}
		}

		$model->password = ""; // don't display the hashed password to the end user
		if (Yii::app()->request->isAjaxRequest) {
			$this->renderPartial("settings",array("model" => $model),false,true);
		}
		else {
			$this->render("settings",array("model" => $model));
		}
	}
	/**
	 * Sets a user's preference
	 * @throws CHttpException if the request is invalid
	 */
	public function actionSetPreference() {
		if (Yii::app()->user->isGuest) {
			Yii::app()->user->loginRequired(); // access rules should be used to prevent this occurring
		}
		if (!isset($_POST['name']) || !isset($_POST['value']) || !Yii::app()->request->isAjaxRequest) {
			throw new CHttpException(400, "Invalid Request");
		}
		$model = Yii::app()->user->getModel(); /* @var AUser $model */
		$model->setPreference($_POST['name'],$_POST['value']);
		header("Content-type: application/json");
		echo json_encode($model->getPreference($_POST['name']));
	}

	public function actionMessages() {
		if (Yii::app()->user->isGuest) {
			Yii::app()->user->loginRequired(); // access rules should be used to prevent this occurring
		}
		$model = Yii::app()->user->getModel(); /* @var AUser $model */

		if (Yii::app()->request->isAjaxRequest) {
			$this->renderPartial("messages",array("model" => $model),false,true);
		}
		else {
			$this->render("messages",array("model" => $model));
		}
	}

	/**
	 * Performs the AJAX validation.
	 * @param CModel $model the model to be validated
	 */
	protected function performAjaxValidation($model) {
		if(isset($_POST['ajax'])) {
			echo CActiveForm::validate($model);
			Yii::app()->end();
		}
	}
}
