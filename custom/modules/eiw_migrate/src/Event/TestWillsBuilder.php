<?php

namespace Drupal\eiw_migrate\Event;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\migrate\Row;
use Drupal\migrate_plus\Event\MigratePrepareRowEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Builds the related records of the test wills (eiw_9_test_wills).
 *
 * Each test will row lists its voyages (voyage title, ship title, role
 * name) and a PDF from data/pdf. Before the row is imported, this creates
 * its "Will voyage" paragraphs, referencing voyages, ships and roles that
 * already exist (none are created; a name that isn't found is left out),
 * and saves its PDF as a file, so the migration can reference both.
 */
class TestWillsBuilder implements EventSubscriberInterface {

  /**
   * The migration this acts on.
   */
  const MIGRATION = 'eiw_9_test_wills';

  /**
   * Constructs a TestWillsBuilder.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
    protected ModuleExtensionList $moduleList,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // MigratePlusEvents::PREPARE_ROW, by value; see ItemMigrateEvent.
    return ['migrate_plus.prepare_row' => ['onPrepareRow', 0]];
  }

  /**
   * Creates a test will's voyage paragraphs and PDF file.
   */
  public function onPrepareRow(MigratePrepareRowEvent $event): void {
    if ($event->getMigration()->id() !== self::MIGRATION) {
      return;
    }
    $row = $event->getRow();
    // Rows already imported are skipped after this event unless updating;
    // don't create records for them that nothing would reference.
    if (!empty($row->getIdMap()['destid1']) && !$row->needsUpdate()) {
      return;
    }

    $row->setSourceProperty('will_voyage_refs', $this->willVoyages($row));
    $row->setSourceProperty('document_ref', $this->document($row));
  }

  /**
   * Creates the row's "Will voyage" paragraphs.
   *
   * @return array
   *   Field values for field_will_voyages.
   */
  protected function willVoyages(Row $row): array {
    $values = [];
    foreach ($row->getSourceProperty('voyages') ?? [] as $voyage) {
      $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
        'type' => 'eiw_will_voyage',
        'field_voyage' => $this->nodeId('eiw_voyage', $voyage['voyage'] ?? ''),
        'field_ship' => $this->nodeId('eiw_ship', $voyage['ship'] ?? ''),
        'field_role' => $this->termId('eiw_roles', $voyage['role'] ?? ''),
      ]);
      $paragraph->save();
      $values[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }
    return $values;
  }

  /**
   * Saves the row's PDF from data/pdf as a permanent file.
   *
   * @return int|null
   *   The file ID, or NULL if the row has none or it couldn't be saved.
   */
  protected function document(Row $row): ?int {
    $name = basename((string) $row->getSourceProperty('pdf'));
    $path = $this->moduleList->getPath('eiw_migrate') . "/data/pdf/$name";
    if (!$name || !is_file($path)) {
      return NULL;
    }
    $directory = 'public://eiw_test_wills';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $file = $this->fileRepository->writeData(file_get_contents($path), "$directory/$name", FileExists::Replace);
    return $file?->id();
  }

  /**
   * Finds a node by exact title.
   */
  protected function nodeId(string $bundle, string $title): ?int {
    if ($title === '') {
      return NULL;
    }
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('title', $title)
      ->range(0, 1)
      ->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * Finds a taxonomy term by exact name.
   */
  protected function termId(string $vid, string $name): ?int {
    if ($name === '') {
      return NULL;
    }
    $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vid)
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

}
