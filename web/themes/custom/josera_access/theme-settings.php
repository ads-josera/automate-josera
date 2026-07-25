<?php

declare(strict_types=1);

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\file\Entity\File;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function josera_access_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state): void {
  $theme_settings = \Drupal::service(ThemeSettingsProvider::class);

  $form['josera_access'] = [
    '#type' => 'details',
    '#title' => t('Login appearance'),
    '#open' => TRUE,
    '#description' => t('Customize the public login page for the automation platform.'),
  ];
  $form['josera_access']['login_primary_color'] = [
    '#type' => 'color',
    '#title' => t('Primary color'),
    '#default_value' => $theme_settings->getSetting('login_primary_color') ?: '#20105d',
    '#config_target' => 'josera_access.settings:login_primary_color',
  ];
  $form['josera_access']['login_accent_color'] = [
    '#type' => 'color',
    '#title' => t('Accent color'),
    '#default_value' => $theme_settings->getSetting('login_accent_color') ?: '#fb167c',
    '#config_target' => 'josera_access.settings:login_accent_color',
  ];
  $form['josera_access']['login_logo'] = [
    '#type' => 'managed_file',
    '#title' => t('Login logo'),
    '#default_value' => $theme_settings->getSetting('login_logo') ?: [],
    '#description' => t('Upload a SVG, PNG, or JPG logo. A transparent SVG is recommended.'),
    '#upload_location' => 'public://josera-access',
    '#upload_validators' => [
      'FileExtension' => ['extensions' => 'svg png jpg jpeg'],
    ],
    '#config_target' => 'josera_access.settings:login_logo',
  ];
  $form['#submit'][] = 'josera_access_theme_settings_submit';
}

/**
 * Makes the selected logo available after the temporary upload expires.
 */
function josera_access_theme_settings_submit(array &$form, FormStateInterface $form_state): void {
  $file_ids = $form_state->getValue('login_logo') ?: [];
  foreach ($file_ids as $file_id) {
    if ($file = File::load($file_id)) {
      $file->setPermanent();
      $file->save();
    }
  }
}
