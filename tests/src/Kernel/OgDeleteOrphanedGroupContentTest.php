<?php

namespace Drupal\Tests\og\Kernel;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\Entity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\og\Og;
use Drupal\og\OgGroupAudienceHelper;

/**
 * Tests deletion of orphaned group content.
 *
 * @group og
 */
class OgDeleteOrphanedGroupContentTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['system', 'user', 'field', 'entity_reference', 'node', 'og'];

  /**
   * The plugin manager for OgDeleteOrphans plugins.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $ogDeleteOrphansPluginManager;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Add membership and config schema.
    $this->installConfig(['og']);
    $this->installEntitySchema('og_membership');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('system', 'sequences');

    /** @var \Drupal\og\OgDeleteOrphansPluginManager ogDeleteOrphansPluginManager */
    $this->ogDeleteOrphansPluginManager = \Drupal::service('plugin.manager.og.delete_orphans');

    // Create a group.
    $this->groupBundle = Unicode::strtolower($this->randomMachineName());
    NodeType::create([
      'type' => $this->groupBundle,
      'name' => $this->randomString(),
    ])->save();
    Og::groupManager()->addGroup('node', $this->groupBundle);

    // Create a group content type.
    $this->groupContentBundle = Unicode::strtolower($this->randomMachineName());
    NodeType::create([
      'type' => $this->groupContentBundle,
      'name' => $this->randomString(),
    ])->save();
    Og::createField(OgGroupAudienceHelper::DEFAULT_FIELD, 'node', $this->groupContentBundle);
  }

  /**
   * Tests that orphaned group content is deleted when the group is deleted.
   *
   * @dataProvider ogDeleteOrphansPluginProvider
   *
   * @param string $plugin_id
   */
  public function testDeleteOrphans($plugin_id) {
    // Create a group.
    $group = Node::create([
      'title' => $this->randomString(),
      'type' => $this->groupBundle,
    ]);
    $group->save();

    // Create a group content item.
    $group_content = Node::create([
      'title' => $this->randomString(),
      'type' => $this->groupContentBundle,
      OgGroupAudienceHelper::DEFAULT_FIELD => [['target_id' => $group->id()]],
    ]);
    $group_content->save();

    // Delete the group.
    $group->delete();

    // Invoke the processing of the orphans.
    /** @var \Drupal\og\OgDeleteOrphansInterface $plugin */
    $plugin = $this->ogDeleteOrphansPluginManager->createInstance($plugin_id, []);
    $plugin->process();

    // Reload the group content that was used during the test.
    $group_content = Node::load($group_content->id());

    // Verify the orphaned node is deleted.
    $this->assertFalse($group_content, 'The orphaned node is deleted.');
  }

  /**
   * Provides OgDeleteOrphans plugins for the tests.
   *
   * @return array
   */
  public function ogDeleteOrphansPluginProvider() {
    return [
      ['batch'],
      ['cron'],
      ['simple'],
    ];
  }

  /**
   * Tests that group content is handled appropriately when a group is deleted.
   *
   * - Group content that only belongs to a single group should be deleted.
   * - Group content associated with multiple groups should not be deleted, but
   *   its references should be updated.
   */
  function _testDeleteGroup() {
    // Creating two groups.
    $first_group = $this->drupalCreateNode(array('type' => $this->group_type));
    $second_group = $this->drupalCreateNode(array('type' => $this->group_type));

    // Create two nodes.
    $first_node = $this->drupalCreateNode(array('type' => $this->node_type));
    og_group('node', $first_group, array('entity_type' => 'node', 'entity' => $first_node));
    og_group('node', $second_group, array('entity_type' => 'node', 'entity' => $first_node));

    $second_node = $this->drupalCreateNode(array('type' => $this->node_type));
    og_group('node', $first_group, array('entity_type' => 'node', 'entity' => $second_node));

    // Delete the group.
    node_delete($first_group->nid);

    // Execute manually the queue worker.
    $queue = DrupalQueue::get('og_membership_orphans');
    $item = $queue->claimItem();
    og_membership_orphans_worker($item->data);

    // Load the nodes we used during the test.
    $first_node = node_load($first_node->nid);
    $second_node = node_load($second_node->nid);

    // Verify the none orphan node wasn't deleted.
    $this->assertTrue($first_node, "The second node is realted to another group and deleted.");
    // Verify the orphan node deleted.
    $this->assertFalse($second_node, "The orphan node deleted.");
  }

  /**
   * Tests the moving of the node to another group when deleting a group.
   *
   * @todo This test doesn't make any sense to me. The way I read it is that if
   *   multiple groups are present and one of the groups is deleted then its
   *   content is expected to be moved into a random other group? This seems
   *   dangerous, it might expose private data.
   *
   *   This might be useful for child groups that are related to parent groups,
   *   so that when a child group is deleted its content will be moved to the
   *   parent, but then there should be a very clear indication of the parent-
   *   child relation which is missing in this test. Here the content just moves
   *   to whatever random group that is available.
   */
  function _testMoveOrphans() {
    // Creating two groups.
    $first_group = $this->drupalCreateNode(array('type' => $this->group_type, 'title' => 'move'));
    $second_group = $this->drupalCreateNode(array('type' => $this->group_type));

    // Create a group and relate it to the first group.
    $first_node = $this->drupalCreateNode(array('type' => $this->node_type));
    og_group('node', $first_group, array('entity_type' => 'node', 'entity' => $first_node));

    // Delete the group.
    node_delete($first_group->nid);

    // Execute manually the queue worker.
    $queue = DrupalQueue::get('og_membership_orphans');
    $item = $queue->claimItem();
    og_membership_orphans_worker($item->data);

    // Load the node into a wrapper and verify we moved him to another group.
    $gids = og_get_entity_groups('node', $first_node->nid);
    $gid = reset($gids['node']);

    $this->assertEqual($gid, $second_group->nid, 'The group content moved to another group.');
  }

}
