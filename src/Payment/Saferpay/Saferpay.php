<?php

namespace Payment\Saferpay;

use Psr\Http\Client\ClientInterface;
use Http\Message\MessageFactory;

use Payment\Saferpay\Data\Collection\CollectionItemInterface;
use Payment\Saferpay\Data\PayCompleteParameter;
use Payment\Saferpay\Data\PayCompleteParameterInterface;
use Payment\Saferpay\Data\PayCompleteResponse;
use Payment\Saferpay\Data\PayConfirmParameter;
use Payment\Saferpay\Data\PayInitParameterInterface;
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
    public function setHttpClient(ClientInterface $httpClient): self
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    /**
     * @return ClientInterface
     * @throws \Exception
     */
    protected function getHttpClient(): ClientInterface
    {
        if ($this->httpClient === null) {
            throw new \RuntimeException('Please define a http client based on the ClientInterface!');
        }

        return $this->httpClient;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
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
     * @param CollectionItemInterface $payInitParameter
     * @return mixed
     * @throws \Exception
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function createPayInit(CollectionItemInterface $payInitParameter)
    {
        return $this->request($payInitParameter->getRequestUrl(), $payInitParameter->getData());
    }

    /**
     * @param $xml
     * @param $signature
     * @param CollectionItemInterface $payConfirmParameter
     * @return CollectionItemInterface
     * @throws \Exception
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function verifyPayConfirm($xml, $signature, CollectionItemInterface $payConfirmParameter = null)
    {
        if ($payConfirmParameter === null) {
            $payConfirmParameter = new PayConfirmParameter();
        }

        $this->fillDataFromXML($payConfirmParameter, $xml);
        $this->request($payConfirmParameter->getRequestUrl(), array(
            'DATA' => $xml,
            'SIGNATURE' => $signature
        ));

        return $payConfirmParameter;
    }

    /**
     * @param  CollectionItemInterface            $payConfirmParameter
     * @param  string                             $action
     * @param  null                               $spPassword
     * @param  CollectionItemInterface            $payCompleteParameter
     * @param  CollectionItemInterface            $payCompleteResponse
     * @return CollectionItemInterface
     * @throws Exception\NoPasswordGivenException
     * @throws \Exception
     */
    public function payCompleteV2(
        CollectionItemInterface $payConfirmParameter,
        $action = PayCompleteParameterInterface::ACTION_SETTLEMENT,
        $spPassword = null,
        CollectionItemInterface $payCompleteParameter = null,
        CollectionItemInterface $payCompleteResponse = null
    ) {
        if ($payConfirmParameter->get('ID') === null) {
            $this->getLogger()->critical('Saferpay: call confirm before complete!');
            throw new \Exception('Saferpay: call confirm before complete!');
        }

        if ($payCompleteParameter === null) {
            $payCompleteParameter = new PayCompleteParameter();
        }

        $payCompleteParameter->set('ID', $payConfirmParameter->get('ID'));
        $payCompleteParameter->set('AMOUNT', $payConfirmParameter->get('AMOUNT'));
        $payCompleteParameter->set('ACCOUNTID', $payConfirmParameter->get('ACCOUNTID'));
        $payCompleteParameter->set('ACTION', $action);

        $payCompleteParameterData = $payCompleteParameter->getData();

        if ($this->isTestAccountId($payCompleteParameter->get('ACCOUNTID'))) {
            $payCompleteParameterData = array_merge($payCompleteParameterData, array('spPassword' => PayInitParameterInterface::SAFERPAYTESTACCOUNT_SPPASSWORD));
        } elseif ($action !== PayCompleteParameterInterface::ACTION_SETTLEMENT && !$spPassword) {
            throw new NoPasswordGivenException();
        }

        $response = $this->request($payCompleteParameter->getRequestUrl(), $payCompleteParameterData);

        if ($payCompleteResponse === null) {
            $payCompleteResponse = new PayCompleteResponse();
        }

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
     * @param CollectionItemInterface $data
     * @param $xml
     * @throws \Exception
     */
    protected function fillDataFromXML(CollectionItemInterface $data, $xml): void
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
     * @param  string $accountId
     * @return bool
     */
    protected function isTestAccountId($accountId): bool
    {
        $prefix = PayInitParameterInterface::TESTACCOUNT_PREFIX;

        return strpos($accountId, $prefix) === 0;
    }
}
