<?php

namespace Drupal\Tests\og\Kernel\Action;

use Drupal\og\OgMembershipInterface;

/**
 * Tests the DeleteOgMembership action plugin.
 *
 * @group og
 * @coversDefaultClass \Drupal\og\Plugin\Action\DeleteOgMembership
 */
class DeleteOgMembershipActionTest extends ChangeOgMembershipActionTestBase {

  /**
   * {@inheritdoc}
   */
  protected $pluginId = 'og_membership_delete_action';

  /**
   * Checks if the action can be performed correctly.
   *
   * @param string $membership
   *   The membership on which to perform the action.
   *
   * @covers ::execute
   * @dataProvider executeProvider
   */
  public function testExecute($membership = NULL) {
    $membership = $this->memberships[$membership];
    $member = $membership->getUser();
    /** @var \Drupal\og\Plugin\Action\AddSingleOgMembershipRole $plugin */
    $configuration = !empty($default_role_name) ? ['role_name' => $default_role_name] : [];
    $plugin = $this->getPlugin($configuration);
    $plugin->execute($membership);

    $this->assertFalse($this->membershipManager->isMember($this->group, $member, [
      OgMembershipInterface::STATE_ACTIVE,
      OgMembershipInterface::STATE_BLOCKED,
      OgMembershipInterface::STATE_PENDING,
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function executeProvider() {
    return [
      ['member'],
      ['pending'],
      ['blocked'],
      ['group_administrator'],
      ['group_moderator'],
    ];
  }

}
