<?php
include_once __DIR__ . '/../sdk/GSSDK.php';
require_once ('Mage/Customer/controllers/AccountController.php');
/**
 * Class Gigya_Social_IndexController
 * @author
 */
class Gigya_Social_LoginController extends Mage_Customer_AccountController
{
  /**
   * Action predispatch
   *
   * Check customer authentication for some actions
   */
  public function preDispatch()
  {
    // a brute-force protection here would be nice


    if (!$this->getRequest()->isDispatched()) {
      return;
    }

    $action = $this->getRequest()->getActionName();
    $openActions = array(
      'login',
      'addemail'
    );
    $pattern = '/^(' . implode('|', $openActions) . ')/i';

    if (!preg_match($pattern, $action)) {
      if (!$this->_getSession()->authenticate($this)) {
        $this->setFlag('', 'no-dispatch', true);
      }
    } else {
      $this->_getSession()->setNoReferer(true);
    }
  }

  public function indexAction()
  {
    $this->loadLayout();
    $this->renderLayout();
  }

  public function loginAction()
  {
    $session = $this->_getSession();
    $post = $this->getRequest()->getPost();
    if (!empty($post) && isset($post['signature'])) {

      $secret = Mage::getStoreConfig('gigya_global/gigya_global_conf/secretkey');
      $valid = SigUtils::validateUserSignature($post['UID'], $post['timestamp'], $secret, $post['signature']);
      $firstName = $post['firstName'];
      $lastName = $post['lastName'];
      $email = $post['email'];
      if ($valid == TRUE) {
        //no email
        if (empty($post['email'])) {
          //return email form
          Mage::log('no Email');
          $block = $this->getLayout()->createBlock(
            'Mage_Core_Block_Template',
            'Emailform',
            array('template' => 'gigya/form/emailForm.phtml')
          );
          $form = $block->renderView();
          $res = array(
            'html' => $form,
            'id' => Mage::getStoreConfig('gigya_login/gigya_login_conf/loginContainerId'),
          );
          $this->getResponse()->setHeader('Content-type', 'application/json');
          $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
        }
        else {
          //check if we have the email on the system
          $customer = $this->_customerExists($post['email']);
          if ($customer === FALSE) {
            $this->_createCustomer($post['email'], $firstName, $lastName);
            $this->getResponse()->setHeader('Content-type', 'application/json');
          }
          else {
            //email exsites
            try {
              Mage::getSingleton('customer/session')->loginById($customer->getId());
              $this->getResponse()->setHeader('Content-type', 'application/json');
              $url = Mage::getUrl('customer/account');
              echo json_encode(
                array(
                  'redirect' => $url
                )
              );
            }
            catch (Exception $e) {
              //TODO:add error handeling
              Mage::log('ffffff');
              Mage::log($e);
            }
          }
        }
      }
      else {
        //not valid
        Mage::log('eeee');
      }
    }

  }
  protected function _createCustomer($email, $firstName = NULL, $lastName = NULL)
  {
    $customer = Mage::getModel('customer/customer')->setId(null);
    $customer->getGroupId();
    $customer->setFirstname($firstName);
    $customer->setLastname($lastName);
    $customer->setEmail($email);
    $password = Mage::helper('Gigya_Social')->_getPassword();
    $_POST['password'] = $password;
    $_POST['confirmation'] = $password;
    Mage::register('current_customer', $customer);
    $this->_forward('createPost');
  }

  protected function _customerExists($email, $websiteId = null)
  {
    $customer = Mage::getModel('customer/customer');
    if ($websiteId) {
      $customer->setWebsiteId($websiteId);
    }
    else {
      $customer->setWebsiteId(Mage::app()->getWebsite()->getId());
    }
    $customer->loadByEmail($email);
    if ($customer->getId()) {
      return $customer;
    }
    return FALSE;
  }

  public function addEmailAction()
  {
    if ($this->getRequest()->isPost()) {
    Mage::log('email');
    Mage::log($this->getRequest()->getPost('email'));
    $email = $this->getRequest()->getPost('email');
    //make sure we don't have the email in the system
    $customer = $this->_customerExists($post['email']);
    if ($customer === FALSE) {

    }
    }
  }


  public function createPostAction()
  {
    //TODO: Deal with logedin user
    $session = $this->_getSession();
    if ($session->isLoggedIn()) {
      Mage::log('loggedIn');
      echo '{bla: ok}';
      //$this->_redirect('*/*/');
      return;
    }
    $session->setEscapeMessages(true); // prevent XSS injection in user input
    if ($this->getRequest()->isPost()) {
      $errors = array();

      if (!$customer = Mage::registry('current_customer')) {
        $customer = Mage::getModel('customer/customer')->setId(null);
      }

      /* @var $customerForm Mage_Customer_Model_Form */
      $customerForm = Mage::getModel('customer/form');
      $customerForm->setFormCode('customer_account_create')
        ->setEntity($customer);

      $customerData = $customerForm->extractData($this->getRequest());

      if ($this->getRequest()->getParam('is_subscribed', false)) {
        $customer->setIsSubscribed(1);
      }

      /**
       * Initialize customer group id
       */
      $customer->getGroupId();

      if ($this->getRequest()->getPost('create_address')) {
        /* @var $address Mage_Customer_Model_Address */
        $address = Mage::getModel('customer/address');
        /* @var $addressForm Mage_Customer_Model_Form */
        $addressForm = Mage::getModel('customer/form');
        $addressForm->setFormCode('customer_register_address')
          ->setEntity($address);

        $addressData    = $addressForm->extractData($this->getRequest(), 'address', false);
        $addressErrors  = $addressForm->validateData($addressData);
        if ($addressErrors === true) {
          $address->setId(null)
            ->setIsDefaultBilling($this->getRequest()->getParam('default_billing', false))
            ->setIsDefaultShipping($this->getRequest()->getParam('default_shipping', false));
          $addressForm->compactData($addressData);
          $customer->addAddress($address);

          $addressErrors = $address->validate();
          if (is_array($addressErrors)) {
            $errors = array_merge($errors, $addressErrors);
          }
        } else {
          $errors = array_merge($errors, $addressErrors);
        }
      }

      try {
        $customerErrors = $customerForm->validateData($customerData);
        if ($customerErrors !== true) {
          $errors = array_merge($customerErrors, $errors);
        } else {
          $customerForm->compactData($customerData);
          $customer->setPassword($this->getRequest()->getPost('password'));
          $customer->setConfirmation($this->getRequest()->getPost('confirmation'));
          $customerErrors = $customer->validate();
          if (is_array($customerErrors)) {
            $errors = array_merge($customerErrors, $errors);
          }
        }

        $validationResult = count($errors) == 0;

        if (true === $validationResult) {
          $customer->save();

          Mage::dispatchEvent('customer_register_success',
            array('account_controller' => $this, 'customer' => $customer)
          );

          if ($customer->isConfirmationRequired()) {
            $customer->sendNewAccountEmail(
              'confirmation',
              $session->getBeforeAuthUrl(),
              Mage::app()->getStore()->getId()
            );
            $session->addSuccess($this->__('Account confirmation is required. Please, check your email for the confirmation link. To resend the confirmation email please <a href="%s">click here</a>.', Mage::helper('customer')->getEmailConfirmationUrl($customer->getEmail())));
            //$this->_redirectSuccess(Mage::getUrl('*/*/index', array('_secure'=>true)));
            return;
          } else {
            $session->setCustomerAsLoggedIn($customer);
            //$url = $this->_welcomeCustomer($customer);
            $url = Mage::getUrl('customer/account');
            //$this->_redirectSuccess($url);
            echo json_encode(
              array(
                'redirect' => $url
              )
            );
            return;
          }
        } else {
          $session->setCustomerFormData($this->getRequest()->getPost());
          if (is_array($errors)) {
            foreach ($errors as $errorMessage) {
              $session->addError($errorMessage);
            }
          } else {
            $session->addError($this->__('Invalid customer data'));
          }
        }
      } catch (Mage_Core_Exception $e) {
        $session->setCustomerFormData($this->getRequest()->getPost());
        if ($e->getCode() === Mage_Customer_Model_Customer::EXCEPTION_EMAIL_EXISTS) {
          $url = Mage::getUrl('customer/account/forgotpassword');
          $message = $this->__('There is already an account with this email address. If you are sure that it is your email address, <a href="%s">click here</a> to get your password and access your account.', $url);
          $session->setEscapeMessages(false);
        } else {
          $message = $e->getMessage();
        }
        $session->addError($message);
      } catch (Exception $e) {
        $session->setCustomerFormData($this->getRequest()->getPost())
          ->addException($e, $this->__('Cannot save the customer.'));
      }
    }

    Mage::log('error');
    //$this->_redirectError(Mage::getUrl('*/*/create', array('_secure' => true)));
  }

}

