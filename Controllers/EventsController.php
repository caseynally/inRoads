<?php
/**
 * @copyright 2014-2015 City of Bloomington, Indiana
 * @license http://www.gnu.org/licenses/agpl.txt GNU/AGPL, see LICENSE.txt
 * @author Cliff Ingham <inghamn@bloomington.in.gov>
 */
namespace Application\Controllers;

use Application\Models\Event;
use Application\Models\EventType;
use Application\Models\GoogleGateway;
use Application\Models\Person;
use Application\Template\Helpers\ButtonLink;
use Blossom\Classes\ActiveRecord;
use Blossom\Classes\Block;
use Blossom\Classes\Controller;

class EventsController extends Controller
{
    /**
     * Tries to load an event, and handles any errors
     *
     * @return Event
     */
    private function loadEvent($id)
    {
        if ($id) {
            $event = GoogleGateway::getEvent(GOOGLE_CALENDAR_ID, $id);
            if ($event) {
                return new Event($event);
            }
            else {
                $this->template->setFlashMessages( ['event' => [0 => ['unknown']]], 'errorMessages' );
                header('Location: '.BASE_URL.'/events');
                exit();
            }
        }
        else {
            $this->template->setFlashMessages( ['event' => [0 => ['unknown']]], 'errorMessages' );
            header('Location: '.BASE_URL.'/events');
            exit();
        }
    }

    /**
     * Creates variables from the searchForm submission
     *
     * @return array
     */
    private function getSearchParameters()
    {
        if (!empty($_GET['start'])) {
            try {
                $start = ActiveRecord::parseDate($_GET['start'], DATE_FORMAT);
                $start->setTime(0, 0);
            }
            catch (\Exception $e) {
            }
        }
        if (!empty($_GET['end'])) {
            try {
                $end = ActiveRecord::parseDate($_GET['end'], DATE_FORMAT);
                $end->setTime(23, 59);
            }
            catch (\Exception $e) {
            }
        }

        if (!isset($start)) { $start = new \DateTime(); $start->setTime(0,  0); }
        if (!isset($end  )) { $end   = new \DateTime(); $end  ->setTime(23, 59); }

        $filters['eventTypes'] = [];
        if (!empty($_GET['eventTypes'])) {
            $filters['eventTypes'] = $_GET['eventTypes'];
        }
        else {
            foreach (EventType::types() as $type) {
                if ($type->isDefaultForSearch()) { $filters['eventTypes'][] = $type->getCode(); }
            }
        }
        return ['start'=>$start, 'end'=>$end, 'filters'=>$filters];
    }

    public function index()
    {
        $search = $this->getSearchParameters();
        $events = GoogleGateway::getEvents(
            GOOGLE_CALENDAR_ID,
            $search['start'],
            $search['end'],
            $search['filters']
        );

        $this->template->title = $this->template->_('upcoming_closures');
        $scheduleBlock   = new Block('events/schedule.inc',   ['events'=>$events]);
        $searchFormBlock = new Block('events/searchForm.inc', ['start'=>$search['start'], 'end'=>$search['end'], 'filters'=>$search['filters']]);
        $eventListBlock  = new Block('events/list.inc',       ['events'=>$events]);
        $mapBlock        = new Block('events/map.inc',        ['events'=>$events]);

        if ($this->template->outputFormat === 'html') {
            if (Person::isAllowed('events', 'update')) {
                $helper = $this->template->getHelper('buttonLink');
                $this->template->headerToolsButton = $helper->buttonLink(
                    BASE_URI.'/events/update',
                    $this->template->_('event_add'),
                    'add'
                );
            }
            $this->template->blocks['panel-one'][] = $searchFormBlock;

            if (!empty($_GET['view']) && $_GET['view'] === 'schedule') {
                $this->template->setFilename('schedule');
                $this->template->blocks[] = $scheduleBlock;
            }
            else {
                $this->template->blocks['panel-two'][] = $eventListBlock;
                $this->template->blocks[]              = $mapBlock;
            }
        }
        else {
            $this->template->blocks[] = $eventListBlock;
        }

    }

    public function view()
    {
        $this->template->setFilename('two-column');
        $event = $this->loadEvent($_GET['id']);

        $search = $this->getSearchParameters();
        $events = GoogleGateway::getEvents(
            GOOGLE_CALENDAR_ID,
            $search['start'],
            $search['end'],
            $search['filters']
        );

        $this->template->title = $event->getType();

        $return_uri = !empty($_GET['return_uri']) ? $_GET['return_uri'] : BASE_URI.'/events';
        $helper = $this->template->getHelper('buttonLink');
        $this->template->headerToolsButton = $helper->buttonLink(
            $return_uri,
            $this->template->_('back'),
            'back'
        );
        if (Person::isAllowed('events', 'update')) {
            $this->template->headerToolsButton.= $helper->buttonLink(
                BASE_URI.'/events/update?id='.$event->getId(),
                $this->template->_('event_edit'),
                'edit'
            );
        }

        $this->template->blocks['panel-one'][] = new Block('events/single.inc', ['event'=>$event]);
        $this->template->blocks[]              = new Block('events/map.inc', ['event'=>$event]);
    }

    public function update()
    {
        $this->template->setFilename('two-column');
        $event =        !empty($_REQUEST['id'])
            ? $this->loadEvent($_REQUEST['id'])
            : new Event();

        if (isset($_POST['id'])) {
            $event->handleUpdate($_POST);
            try {
                $event->save();
                header('Location: '.BASE_URL.'/events/view?id='.$event->getId());
                exit();
            }
            catch (\Exception $e) {
                $_SESSION['errorMessages'][] = $e;
            }
        }

        $this->template->title = $event->getId()
            ? $this->template->_('event_edit')
            : $this->template->_('event_add');
        $helper = $this->template->getHelper('buttonLink');
        $this->template->headerToolsButton = $helper->buttonLink(
            BASE_URI.'/events/view?id='.$event->getId(),
            $this->template->_('cancel'),
            'cancel'
        );
        $this->template->blocks['panel-one'][] = new Block('events/updateForm.inc', ['event'=>$event]);
        $this->template->blocks[]              = new Block('events/mapEditor.inc',  ['event'=>$event]);
    }
}
