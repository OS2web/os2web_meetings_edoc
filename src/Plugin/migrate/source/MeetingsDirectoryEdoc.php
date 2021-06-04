<?php

namespace Drupal\os2web_meetings_edoc\Plugin\migrate\source;

use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\node\Entity\Node;
use Drupal\os2web_meetings\Entity\Meeting;
use Drupal\os2web_meetings\Form\SettingsForm;
use Drupal\os2web_meetings\Plugin\migrate\source\MeetingsDirectory;

/**
 * Source plugin for retrieving data via URLs.
 *
 * @MigrateSource(
 *   id = "os2web_meetings_directory_edoc"
 * )
 */
class MeetingsDirectoryEdoc extends MeetingsDirectory {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    // TODO: add import skip.
    // TODO: if we are importing a previous version of the same meeting - SKIP.
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

  /**
   * {@inheritdoc}
   */
  public function getMeetingsManifestPath() {
    return \Drupal::config(SettingsForm::$configName)
      ->get('edoc_meetings_manifest_path');
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaAccessToCanonical(array $source) {
    // TODO: add multiple agendas handling.
    // Skipping multiple agendas.
    if (is_array($source['agenda_access'])) {
      return MeetingsDirectory::AGENDA_ACCESS_CLOSED;
    }

    if (stripos($source['agenda_access'], 'lukket') !== FALSE) {
      return MeetingsDirectory::AGENDA_ACCESS_CLOSED;
    }
    else {
      return MeetingsDirectory::AGENDA_ACCESS_OPEN;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaIdToCanonical(array $source) {
   $meeting_id = crc32($source['meeting_start_date'] . ' ' . $source['committee_name']);//generated meeting_id
  if ($meeting_id > 2147483647){
    $meeting_id = substr($meeting_id, 0,9);//mysql int out of range fix
  }
    return $meeting_id;
  }


  /**
   * {@inheritdoc}
   */
  public function convertAgendaTypeToCanonical(array $source) {
    if (strcasecmp($source['agenda_type'], '"Referat endeligt godkendt"') === 0) {
      return MeetingsDirectory::AGENDA_TYPE_REFERAT;
    }
    else {
      return MeetingsDirectory::AGENDA_TYPE_DAGSORDEN;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function convertStartDateToCanonical(array $source) {
    $start_date = $source['meeting_start_date'] . ' ' . $source['meeting_start_time'];

    return $this->convertDateToTimestamp($start_date);
  }

  /**
   * {@inheritdoc}
   */
  public function convertEndDateToCanonical(array $source) {
    if (isset($source['meeting_end_date']) && $source['meeting_end_time']) {
      $end_date = $source['meeting_end_date'] . ' ' . $source['meeting_end_time'];
    }
    else {
      // Reusing start date.
      $end_date = $source['meeting_start_date'] . ' ' . $source['meeting_start_time'];
    }

    return $this->convertDateToTimestamp($end_date);
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaDocumentToCanonical(array $source) {
    $title = 'Samlet document';
    // There is no reference to HTML file, but we expect it to be in the
    // directory with the following name.
    $uri = 'dagsorden.html';

    return [
      'title' => $title,
      'uri' => $uri,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertCommitteeToCanonical(array $source) {
    $id = $source['committee_name'];
    $name = $source['committee_name'];
    return [
      'id' => $id,
      'name' => $name,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertLocationToCanonical(array $source) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function convertBulletPointsToCanonical(array $source) {
    $canonical_bullet_points = [];
    $source_bullet_points = $source['bullet_points'][0]['MeetingAgendaItem'];

    // Dealing with one BP meeting.
    if (array_key_exists('AgendaItemNumber', $source_bullet_points)) {
      $source_bullet_points = [
        0 => $source_bullet_points
      ];
    }

    foreach ($source_bullet_points as $bullet_point) {
      $id = $bullet_point['Document']['@attributes']['documentid'];
      $bpNumber = $bullet_point['AgendaItemNumber'];
      $title = $bullet_point['Document']['Title'];
      $publishingType = $bullet_point['Document']['PublishingType'];
      $access = ($publishingType !== 'SKAL PUBLICERES')? FALSE : TRUE;
      // Getting attachments (text).
      $canonical_attachments = array([
        'id' => $id . $bpNumber,
        'title' => 'Beskrivelse',
        'body' => nl2br($bullet_point['FullText']),
        'access' => $access,
      ]);
      // Getting enclosures (files).
      $source_enclosures = NULL;
      if (is_array($bullet_point['Document'])) {
        if (array_key_exists('Attachments', $bullet_point['Document'])) {
          $source_enclosures = $bullet_point['Document']['Attachments'] ?? NULL;
        }
      }
      $canonical_enclosures = [];
      if (is_array($source_enclosures)) {
        $canonical_enclosures = $this->convertEnclosuresToCanonical($source_enclosures);
      }

      $canonical_bullet_points[] = [
        'id' => $id,
        'number' => $bpNumber,
        'title' => $title,
        'access' => TRUE,
        'attachments' => $canonical_attachments,
        'enclosures' =>  $canonical_enclosures,
      ];
    }
    usort($canonical_bullet_points, function ($item1, $item2) {
    if ($item1['number'] == $item2['number']) return 0;
    return $item1['number'] < $item2['number'] ? -1 : 1;
    });
    return $canonical_bullet_points;
  }

  /**
   * {@inheritdoc}
   */
  public function convertAttachmentsToCanonical(array $source_attachments, $access = TRUE) {
    $canonical_attachments = [];

    foreach ($source_attachments as $title => $body) {
      // Using title as ID, as we don't have a real one.
      $id = $title;

      $canonical_attachments[] = [
        'id' => $id,
        'title' => $title,
        'body' => $body,
        'access' => TRUE,
      ];
    }

    return $canonical_attachments;
  }

  /**
   * {@inheritdoc}
   */
  public function convertEnclosuresToCanonical(array $source_enclosures) {
    $canonical_enclosures = [];

    foreach ($source_enclosures as $enclosure) {
      $id = $enclosure['@attributes']['attachmentid'];
      $title = $enclosure['Title'];
      $access = TRUE;//filter_var($enclosure['@attributes']['MaaPubliceres'], FILTER_VALIDATE_BOOLEAN);
      $uri = $enclosure['PDFDocument'];

      $canonical_enclosures[] = [
        'id' => $id,
        'title' => $title,
        'uri' => $uri,
        'access' => $access,
      ];

    }

    return $canonical_enclosures;
  }

  /**
   * Converts Danish specific string date into timestamp in UTC.
   *
   * @param string $dateStr
   *   Date as string, e.g. "27. august 2018 16:00".
   *
   * @return int
   *   Timestamp in UTC.
   *
   * @throws \Exception
   */
  private function convertDateToTimestamp($dateStr) {
    $dateStr = str_ireplace([
      ". januar ",
      ". februar ",
      ". marts ",
      ". april ",
      ". maj ",
      ". juni ",
      ". juli ",
      ". august ",
      ". september ",
      ". oktober ",
      ". november ",
      ". december ",
    ],
      [
        "-1-",
        "-2-",
        "-3-",
        "-4-",
        "-5-",
        "-6-",
        "-7-",
        "-8-",
        "-9-",
        "-10-",
        "-11-",
        "-12-",
      ], $dateStr);

    $dateTime = new \DateTime($dateStr, new \DateTimeZone('Europe/Copenhagen'));

    return $dateTime->getTimestamp();
  }
  /**
   * {@inheritdoc}
   */
  public function convertParticipantToCanonical(array $source) {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    parent::postImport($event);

    // Find all meetings.
    $query = \Drupal::entityQuery('node');
    $query->condition('type', 'os2web_meetings_meeting');
    $query->condition('field_os2web_m_source', $this->getPluginId());
    $entity_ids = $query->execute();

    $meetings = Node::loadMultiple($entity_ids);

    // Group meetings as:
    // $groupedMeetings[<meeting_id>][<agenda_id>] = <node_id> .
    $groupedMeetings = [];
    foreach ($meetings as $meeting) {
      $os2webMeeting = new Meeting($meeting);

      $meeting_id = $os2webMeeting->getMeetingId();
      $agenda_id = $os2webMeeting->getEsdhId();

      $groupedMeetings[$meeting_id][$agenda_id] = $os2webMeeting->id();

      // Sorting agendas, so that lowest agenda ID is always the first.
      sort($groupedMeetings[$meeting_id]);
    }

    // Process grouped meetings and set addendum fields.
    foreach ($groupedMeetings as $meeting_id => $agendas) {
      // Skipping if agenda count is 1.
      if (count($agendas) == 1) {
        continue;
      }

      $mainAgendaNodedId = array_shift($agendas);

      foreach ($agendas as $agenda_id => $node_id) {
        // Getting the meeting.
        $os2webMeeting = new Meeting($meetings[$node_id]);

        // Setting addendum field, meeting is saved inside a function.
        $os2webMeeting->setAddendum($mainAgendaNodedId);
      }
    }
  }
}
