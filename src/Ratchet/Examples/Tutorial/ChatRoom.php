<?php
namespace Ratchet\Examples\Tutorial;
use Ratchet\ConnectionInterface;
use Ratchet\Wamp\WampServerInterface;
use Ratchet\Tests\Mock\Connection as ConnectionStub;
use Ratchet\Wamp\WampConnection;
use Guzzle\Http\Message\Request;

class ChatRoom implements WampServerInterface {
    const CTRL_PREFIX = 'ctrl:';
    const CTRL_ROOMS  = 'ctrl:rooms';

    protected $rooms = array();

    protected $roomLookup = array();

    protected $bot;

    public function __construct() {
        // Put a fake connection in each control room so the room is never destroyed
        $this->bot = new WampConnection(new ConnectionStub);

        $this->bot->WAMP = new \StdClass;
        $this->bot->WebSocket = new \StdClass;

        $this->bot->resourceId = -1;
        $this->bot->WAMP->sessionId = 1;

        $this->bot->WebSocket->request = new Request('get', '/');
        $this->bot->WebSocket->request->addCookie('name', 'Lonely Bot');

        $this->onOpen($this->bot);

        $this->rooms[static::CTRL_ROOMS] = new \SplObjectStorage;
        $this->rooms[static::CTRL_ROOMS]->attach($this->bot);

        $this->onCall($this->bot, '1', 'createRoom', array('General'));
        $sent = json_decode($this->bot->last['send'], true);
        $this->bot->gId = $sent[2]['id'];
        $this->onSubScribe($this->bot, $this->bot->gId);
    }

    /**
     * {@inheritdoc}
     */
    public function onOpen(ConnectionInterface $conn) {
        $conn->Chat = new \StdClass;

        $conn->Chat->rooms    = array();
        $conn->Chat->name     = $conn->WAMP->sessionId;
        $conn->Chat->welcomed = false;
        $conn->Chat->alone    = false;

        if (isset($conn->WebSocket)) {
            $conn->Chat->name = $this->escape($conn->WebSocket->request->getCookie('name'));

            if (empty($conn->Chat->name)) {
                $conn->Chat->name  = 'Anonymous ' . $conn->resourceId;
            }
        } else {
            $conn->Chat->name  = 'Anonymous ' . $conn->resourceId;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onClose(ConnectionInterface $conn) {
        foreach ($conn->Chat->rooms as $topic => $one) {
            $this->onUnSubscribe($conn, $topic);
        }
    }

    /**
     * {@inheritdoc}
     */
    function onCall(ConnectionInterface $conn, $id, $fn, array $params) {
        switch ($fn) {
            case 'setName':
            break;

            case 'createRoom':
                $topic = $this->escape($params[0]);
                $created = false;

                if (empty($topic)) {
                    return $conn->callError($id, 'Room name can not be empty');
                }

                if (array_key_exists($topic, $this->roomLookup)) {
                    $roomId = $this->roomLookup[$topic];
                } else {
                    $created = true;
                    $roomId  = uniqid('room-');

                    $this->broadcast(static::CTRL_ROOMS, array($roomId, $topic, 1));
                }

                if ($created) {
                    $this->rooms[$roomId] = new \SplObjectStorage;
                    $this->roomLookup[$topic] = $roomId;

                    return $conn->callResult($id, array('id' => $roomId, 'display' => $topic));
                } else {
                    return $conn->callError($id, array('id' => $roomId, 'display' => $topic));
                }
            break;

            default:
                return $conn->callError($id, 'Unknown call');
            break;
        }
    }

    /**
     * {@inheritdoc}
     */
    function onSubscribe(ConnectionInterface $conn, $topic) {
        // Send all the rooms to the person who just subscribed to the room list
        if (static::CTRL_ROOMS == $topic) {
            foreach ($this->rooms as $room => $patrons) {
                if (!$this->isControl($room)) {
                    $conn->event(static::CTRL_ROOMS, array($room, array_search($room, $this->roomLookup), 1));
                }
            }
        }

        // Room does not exist
        if (!array_key_exists($topic, $this->rooms)) {
            return;
        }

        // Notify everyone this guy has joined the room they're in
        $this->broadcast($topic, array('joinRoom', $conn->WAMP->sessionId, $conn->Chat->name), $conn);

        // List all the people already in the room to the person who just joined
        foreach ($this->rooms[$topic] as $patron) {
            $conn->event($topic, array('joinRoom', $patron->WAMP->sessionId, $patron->Chat->name));
        }

        $this->rooms[$topic]->attach($conn);

        $conn->Chat->rooms[$topic] = 1;

        if ($topic == $this->bot->gId && false === $conn->Chat->welcomed) {
            $conn->Chat->welcomed = true;

            $intro = (strstr($conn->Chat->name, 'Anonymous') ? 'Greetings' : "Hi {$conn->Chat->name}");
            $after = '';

            if (count($this->rooms[$this->bot->gId]) == 2) {
                $after = " Looks like it's just you and I at the moment...I'll play copycat until someone else joins.";
                $conn->Chat->alone = true;
            }

            $conn->event($topic, array(
                'message'
              , $this->bot->WAMP->sessionId
              , "{$intro}! This is an IRC-like chatroom powered by Ratchet.{$after}"
              , date('c')
            ));
        }
    }

    /**
     * {@inheritdoc}
     */
    function onUnSubscribe(ConnectionInterface $conn, $topic) {
        unset($conn->Chat->rooms[$topic]);
        $this->rooms[$topic]->detach($conn);

        if ($this->isControl($topic)) {
            return;
        }

        if ($this->rooms[$topic]->count() == 0) {
            unset($this->rooms[$topic], $this->roomLookup[array_search($topic, $this->roomLookup)]);
            $this->broadcast(static::CTRL_ROOMS, array($topic, 0));
        } else {
            $this->broadcast($topic, array('leftRoom', $conn->WAMP->sessionId));
        }
    }

    /**
     * {@inheritdoc}
     */
    function onPublish(ConnectionInterface $conn, $topic, $event, array $exclude = array(), array $eligible = array()) {
        $event = (string)$event;
        if (empty($event)) {
            return;
        }

        if (!array_key_exists($topic, $conn->Chat->rooms) || !array_key_exists($topic, $this->rooms) || $this->isControl($topic)) {
            // error, can not publish to a room you're not subscribed to
            // not sure how to handle error - WAMP spec doesn't specify
            // for now, we're going to silently fail

            return;
        }

        $event = $this->escape($event);

        $this->broadcast($topic, array('message', $conn->WAMP->sessionId, $event, date('c')));

        if ($topic == $this->bot->gId) {
            if ($event == 'test') {
                return $conn->event($topic, array('message', $this->bot->WAMP->sessionId, 'pass', date('c')));
            }

            if ($event == 'help' || $event == '!help') {
                return $conn->event($topic, array('message', $this->bot->WAMP->sessionId, 'No one can hear you scream in /dev/null', date('c')));
            }

            if ($conn->Chat->alone && count($this->rooms[$topic]) == 2) {
                return $conn->event($topic, array('message', $this->bot->WAMP->sessionId, $event, date('c')));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }

    protected function broadcast($topic, $msg, ConnectionInterface $exclude = null) {
        foreach ($this->rooms[$topic] as $client) {
            if ($client !== $exclude) {
                $client->event($topic, $msg);
            }
        }
    }

    /**
     * @param string
     * @return boolean
     */
    protected function isControl($room) {
        return (boolean)(substr($room, 0, strlen(static::CTRL_PREFIX)) == static::CTRL_PREFIX);
    }

    /**
     * @param string
     * @return string
     */
    protected function escape($string) {
        return htmlspecialchars($string);
    }
}