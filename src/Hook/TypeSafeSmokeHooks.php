<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for TypeSafe smoke tests.
 */
final class TypeSafeSmokeHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return [
      'typesafe_smoke' => [
        'title' => $this->t('TypeSafe smoke tests'),
        'value' => $this->t('Enabled'),
        'description' => $this->t('This testing module adds sample content and automators that make real, billed TypeSafe API calls when content is saved. Uninstall it when testing is finished; uninstalling deletes its content and configuration.'),
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

}
