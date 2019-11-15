<?php

namespace Payment\Saferpay;

use Psr\Http\Client\ClientInterface;
use Http\Message\MessageFactory;

use Payment\Saferpay\Data\AbstractData;
use Payment\Saferpay\Data\PayCompleteParameter;
use Payment\Saferpay\Data\PayCompleteParameterInterface;
use Payment\Saferpay\Data\PayCompleteResponse;
use Payment\Saferpay\Data\PayConfirmParameter;
use Payment\Saferpay\Data\PayInitParameterInterface;
use Payment\Saferpay\Data\PayInitParameterWithDataInterface;
use Payment\Saferpay\Exception\NoPasswordGivenException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Saferpay
{
    /**
     * @var MessageFactory
     */
    protected $messageFactory;

    /**
     * @var ClientInterface
     */
    protected $httpClient;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(MessageFactory $messageFactory = null)
    {
        $this->messageFactory  = $messageFactory;
    }

    /**
     * @param ClientInterface $httpClient
     * @return $this
     */
    public function setHttpClient(ClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    /**
     * @return ClientInterface
     * @throws \Exception
     */
    protected function getHttpClient()
    {
        if ($this->httpClient === null) {
            throw new \Exception('Please define a http client based on the ClientInterface!');
        }

        return $this->httpClient;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return LoggerInterface
     */
    protected function getLogger()
    {
        if ($this->logger === null) {
            $this->logger = new NullLogger();
        }

        return $this->logger;
    }

    /**
     * @param  PayInitParameterWithDataInterface $payInitParameter
     * @return string
     */
    public function createPayInit(PayInitParameterWithDataInterface $payInitParameter)
    {
        return $this->request($payInitParameter->getRequestUrl(), $payInitParameter->getData());
    }

    /**
     * @param $xml
     * @param $signature
     * @return PayConfirmParameter
     */
    public function verifyPayConfirm($xml, $signature)
    {
        $payConfirmParameter = new PayConfirmParameter();
        $this->fillDataFromXML($payConfirmParameter, $xml);
        $this->request($payConfirmParameter->getRequestUrl(), array(
            'DATA' => $xml,
            'SIGNATURE' => $signature
        ));

        return $payConfirmParameter;
    }

    /**
     * @param PayConfirmParameter $payConfirmParameter
     * @param string $action
     * @param string $spPassword
     * @return PayCompleteResponse
     * @throws NoPasswordGivenException
     * @throws \Exception
     */
    public function payCompleteV2(PayConfirmParameter $payConfirmParameter, $action = PayCompleteParameterInterface::ACTION_SETTLEMENT, $spPassword = null)
    {
        if ($payConfirmParameter->getId() === null) {
            $this->getLogger()->critical('Saferpay: call confirm before complete!');
            throw new \Exception('Saferpay: call confirm before complete!');
        }

        $payCompleteParameter = new PayCompleteParameter();
        $payCompleteParameter->setId($payConfirmParameter->getId());
        $payCompleteParameter->setAmount($payConfirmParameter->getAmount());
        $payCompleteParameter->setAccountid($payConfirmParameter->getAccountid());
        $payCompleteParameter->setAction($action);

        $payCompleteParameterData = $payCompleteParameter->getData();

        if ($this->isTestAccountId($payCompleteParameter->getAccountid())) {
            $payCompleteParameterData = array_merge($payCompleteParameterData, array('spPassword' => PayInitParameterInterface::SAFERPAYTESTACCOUNT_SPPASSWORD));
        } else {
            if (!$spPassword) {
                throw new NoPasswordGivenException();
            }
            $payCompleteParameterData = array_merge($payCompleteParameterData, array('spPassword' => $spPassword));
        }

        $response = $this->request($payCompleteParameter->getRequestUrl(), $payCompleteParameterData);

        $payCompleteResponse = new PayCompleteResponse();
        $this->fillDataFromXML($payCompleteResponse, substr($response, 3));

        return $payCompleteResponse;
    }

    /**
     * @param $url
     * @param array $data
     * @return mixed
     * @throws \Exception
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    protected function request($url, array $data)
    {
        $data = http_build_query($data);

        $this->getLogger()->debug($url);
        $this->getLogger()->debug($data);

        $request = $this->messageFactory->createRequest(
            'POST',
            $url,
            ['Content-Type'=> 'application/x-www-form-urlencoded'],
            $data
        );

        $response = $this->getHttpClient()->sendRequest($request);

        $this->getLogger()->debug($response->getBody()->getContents());

        if ($response->getStatusCode() !== 200) {
            $this->getLogger()->critical('Saferpay: request failed with statuscode: {statuscode}!', array('statuscode' => $response->getStatusCode()));
            throw new \Exception('Saferpay: request failed with statuscode: ' . $response->getStatusCode() . '!');
        }

        if (strpos($response->getBody()->getContents(), 'ERROR') !== false) {
            $this->getLogger()->critical('Saferpay: request failed: {content}!', array('content' => $response->getContent()));
            throw new \Exception('Saferpay: request failed: ' . $response->getContent() . '!');
        }

        return $response->getBody()->getContents();
    }

    /**
     * @param AbstractData $data
     * @param $xml
     * @throws \Exception
     */
    protected function fillDataFromXML(AbstractData $data, $xml)
    {
        $document = new \DOMDocument();
        $fragment = $document->createDocumentFragment();

        if (!$fragment->appendXML($xml)) {
            $this->getLogger()->critical('Saferpay: Invalid xml received from saferpay');
            throw new \Exception('Saferpay: Invalid xml received from saferpay!');
        }

        foreach ($fragment->firstChild->attributes as $attribute) {
            /** @var \DOMAttr $attribute */
            $data->set($attribute->nodeName, $attribute->nodeValue);
        }
    }

    /**
     * @param string $accountId
     * @return bool
     */
    protected function isTestAccountId($accountId)
    {
        $prefix = PayInitParameterInterface::TESTACCOUNT_PREFIX;
        return substr($accountId, 0, strlen($prefix)) === $prefix;
    }
}
