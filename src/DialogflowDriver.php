<?php

namespace BotMan\Drivers\Dialogflow;

use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Exceptions\Base\BotManException;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Users\User;
use Dialogflow\Action\Conversation;
use Dialogflow\RichMessage\RichMessage;
use Dialogflow\WebhookClient;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DialogflowDriver extends HttpDriver
{
    const DRIVER_NAME = 'Dialogflow';

    /**
     * @var \Dialogflow\WebhookClient
     */
    protected $agent;

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {
        $this->payload = Collection::make((array) json_decode($request->getContent(), true));
        $this->event = Collection::make((array) $this->payload->get('queryResult'));
        $this->config = Collection::make([]);

        try {
            $this->agent = WebhookClient::fromData((array) json_decode($request->getContent(), true));
        } catch (\Exception $e) {
        }
    }

    /**
     * @param IncomingMessage $matchingMessage
     *
     * @return \BotMan\BotMan\Users\User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        return new User($matchingMessage->getSender());
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        return $this->payload->has('queryResult') || $this->payload->has('result');
    }

    /**
     * @param IncomingMessage $message
     *
     * @return \BotMan\BotMan\Messages\Incoming\Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        if (empty($this->messages)) {
            $intent = $this->agent->getIntent();
            $sessionId = $this->agent->getSession();

            $queryResult = $this->payload->get('queryResult');
            $originalDetectIntentRequest = $this->payload->get('originalDetectIntentRequest');

            $message = $intent;
            $sender = $originalDetectIntentRequest['payload']['user']['userId'] ?? null;
            $recipient = $sessionId;

            $message = new IncomingMessage($message, $sender, $recipient, $this->payload);

            $message->addExtras('apiReply', $queryResult['fulfillmentMessages'] ?? null);
            $message->addExtras('apiAction', $this->agent->getAction());
            $message->addExtras('apiIntent', $this->agent->getIntent());
            $message->addExtras('apiParameters', $this->agent->getParameters());
            $message->addExtras('apiContexts', $this->agent->getContexts());

            $this->messages = [$message];
        }

        return $this->messages;
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return true;
    }

    /**
     * Get Actions on Google DialogflowConversation object.
     *
     * @return null|\Dialogflow\Action\Conversation
     */
    public function getActionConversation()
    {
        return $this->agent->getActionConversation();
    }

    /**
     * @param string|\Dialogflow\RichMessage\RichMessage|\Dialogflow\Action\Conversation $message
     *
     * @throws BotManException
     *
     * @return DialogflowDriver
     */
    public function addMessage($message)
    {
        if(is_array($message) || isset($message->name)){
            $this->agent->setOutgoingContext($message);
            return $this;
        }
        if (!is_string($message) && !$message instanceof RichMessage && !$message instanceof Conversation) {
            throw new BotManException('Invalid message');
        }

        $this->agent->reply($message);

        return $this;
    }

    /**
     * Shortcut to send Dialogflow payload.
     *
     * @return Response
     */
    public function sendMessage()
    {
        return $this->sendPayload($this->agent->render());
    }

    /**
     * Get Dialogflow Agent.
     *
     * @return WebhookClient
     */
    public function getAgent()
    {
        return $this->agent;
    }

    /**
     * @param string|OutgoingMessage|RichMessage|Conversation|WebhookClient $message
     * @param IncomingMessage                                               $matchingMessage
     * @param array                                                         $additionalParameters
     *
     * @return Response
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        if ($message instanceof OutgoingMessage) {
            $text = $message->getText();
            $this->agent->reply($text);

            $attachment = $message->getAttachment();
            if ($attachment instanceof Image) {
                $this->agent->reply(\Dialogflow\RichMessage\Image::create($attachment->getUrl()));
            }
        } elseif ($message instanceof RichMessage || $message instanceof Conversation) {
            $this->agent->reply($message);
        } else {
            $text = $message;
            $this->agent->reply($text);
        }

        return $this->agent->render();
    }

    /**
     * @param mixed $payload
     *
     * @return Response
     */
    public function sendPayload($payload)
    {
        return JsonResponse::create($payload)->send();
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string          $endpoint
     * @param array           $parameters
     * @param IncomingMessage $matchingMessage
     *
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        //
    }
}
