<?php


namespace Xigen\ContactCc\Rewrite\Magento\Contact\Controller\Index;

use Magento\Framework\App\Area;
use Magento\Contact\Model\ConfigInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\DataObject;
use Magento\Framework\App\Request\DataPersistorInterface;
use Xigen\ContactCc\Helper\Email;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Contact\Model\MailInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Mail\Template\TransportBuilder;

/**
 * Class Post
 * @package Xigen\ContactCc\Rewrite\Magento\Contact\Controller\Index
 */
class Post extends \Magento\Contact\Controller\Index\Post
{
    /**
     * @var Context
     */
    private $context;

    /**
     * @var ConfigInterface
     */
    private $contactsConfig;

    /**
     * @var MailInterface
     */
    private $mail;

    /**
     * @var DataPersistorInterface
     */
    private $dataPersistor;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Email
     */
    private $helper;

    /**
     * @var StateInterface
     */
    private $inlineTranslation;

    /**
     * @var TransportBuilder
     */
    private $transportBuilder;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * Post constructor.
     * @param Context $context
     * @param ConfigInterface $contactsConfig
     * @param MailInterface $mail
     * @param DataPersistorInterface $dataPersistor
     * @param Email $helper
     * @param LoggerInterface|null $logger
     * @param TransportBuilder $transportBuilder
     * @param StateInterface $inlineTranslation
     * @param StoreManagerInterface|null $storeManager
     */
    public function __construct(
        Context $context,
        ConfigInterface $contactsConfig,
        MailInterface $mail,
        DataPersistorInterface $dataPersistor,
        Email $helper,
        LoggerInterface $logger = null,
        TransportBuilder $transportBuilder,
        StateInterface $inlineTranslation,
        StoreManagerInterface $storeManager = null
    ) {
        $this->context = $context;
        $this->contactsConfig = $contactsConfig;
        $this->mail = $mail;
        $this->dataPersistor = $dataPersistor;
        $this->logger = $logger ?: ObjectManager::getInstance()->get(LoggerInterface::class);
        $this->helper = $helper;
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        $this->storeManager = $storeManager ?: ObjectManager::getInstance()->get(StoreManagerInterface::class);
        parent::__construct($context, $contactsConfig, $mail, $dataPersistor, $logger);
    }

    /**
     * Contact store
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        try {
            $this->sendEmail($this->validatedParams());
            $this->messageManager->addSuccessMessage(
                __('Thanks for contacting us with your comments and questions. We\'ll respond to you very soon.')
            );
            $this->dataPersistor->clear('contact_us');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->dataPersistor->set('contact_us', $this->getRequest()->getParams());
        } catch (\Exception $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('An error occurred while processing your form. Please try again later.')
            );
            $this->dataPersistor->set('contact_us', $this->getRequest()->getParams());
        }
        return $this->resultRedirectFactory->create()->setPath('contact/index');
    }

    /**
     * Send email
     * @param array $post
     * @throws \Magento\Framework\Exception\MailException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function sendEmail($post)
    {
        $this->send(
            $post['email'],
            ['data' => new DataObject($post)]
        );
    }

    /**
     * Send email
     * @param $replyTo
     * @param array $variables
     * @throws \Magento\Framework\Exception\MailException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function send($replyTo, array $variables)
    {
        $replyToName = !empty($variables['data']['name']) ? $variables['data']['name'] : null;

        $this->inlineTranslation->suspend();
        
        try {
            $copyTo = $this->helper->getEmailCopyTo();
            if (!empty($copyTo) && $this->helper->getCopyMethod() == 'bcc') {
                foreach ($copyTo as $email) {
                    $this->configureEmailTemplate($replyTo, $replyToName, $variables);
                    $this->transportBuilder->addTo($this->contactsConfig->emailRecipient());
                    $this->transportBuilder->addBcc($email);
                }
            }

            $transport = $this->transportBuilder->getTransport();
            $transport->sendMessage();

            $this->sendCopyTo($replyTo, $replyToName, $variables);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    /**
     * Prepare and send copy email message
     * @param string $replyTo
     * @param string $replyToName
     * @param array $variables
     * @return void
     */
    public function sendCopyTo($replyTo, $replyToName, $variables)
    {
        $copyTo = $this->helper->getEmailCopyTo();
        if (!empty($copyTo) && $this->helper->getCopyMethod() == 'copy') {
            foreach ($copyTo as $email) {
                $this->configureEmailTemplate($replyTo, $replyToName, $variables);
                $this->transportBuilder->addTo($email);
                $transport = $this->transportBuilder->getTransport();
                $transport->sendMessage();
            }
        }
    }

    /**
     * Configure email template
     * @param string $replyTo
     * @param string $replyToName
     * @param array $variables
     * @return void
     */
    protected function configureEmailTemplate($replyTo, $replyToName, $variables)
    {
        $this->transportBuilder->setTemplateIdentifier($this->contactsConfig->emailTemplate());
        $this->transportBuilder->setTemplateOptions([
            'area' => Area::AREA_FRONTEND,
            'store' => $this->storeManager->getStore()->getId()
        ]);
        $this->transportBuilder->setTemplateVars($variables);
        $this->transportBuilder->setFrom($this->contactsConfig->emailSender());
        $this->transportBuilder->setReplyTo($replyTo, $replyToName);
    }

    /**
     * Validate form params
     * @return array
     * @throws \Exception
     */
    private function validatedParams()
    {
        $request = $this->getRequest();
        if (trim($request->getParam('name')) === '') {
            throw new LocalizedException(__('Name is missing'));
        }
        if (trim($request->getParam('comment')) === '') {
            throw new LocalizedException(__('Comment is missing'));
        }
        if (false === \strpos($request->getParam('email'), '@')) {
            throw new LocalizedException(__('Invalid email address'));
        }
        if (trim($request->getParam('hideit')) !== '') {
            throw new LocalizedException(__('Error'));
        }
        return $request->getParams();
    }
}
