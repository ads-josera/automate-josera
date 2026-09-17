<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Lead;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests lead creation from a qualified conversation.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class LeadHandoffServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'options',
    'text',
    'views',
    'ai_whatsapp_automation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $entity_type_ids = [
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
      'ai_whatsapp_lead',
      'ai_whatsapp_operator_action',
    ];
    foreach ($entity_type_ids as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')
      ->set('options.enable_lead_notifications', TRUE)
      ->save();
  }

  /**
   * The lead stores its origin and correctly extracted contact data.
   *
   * Replays production conversation 28 with the JG Mylard handoff settings.
   */
  public function testLeadStoresOriginAndContactData(): void {
    $storage = fn (string $type) => $this->container->get('entity_type.manager')->getStorage($type);

    $bot = $storage('ai_whatsapp_bot')->create([
      'name' => 'JG Mylard',
      'status' => 'active',
      'handoff_enabled' => TRUE,
      'handoff_minimum_fields' => 6,
      'handoff_required_fields' => "empresa, negocio\nmercancía, mercancia, carga\norigen, salida\ndestino, entrega\nmedio, transporte, terrestre\nvalor, usd, mxn\nfrecuencia, embarques\ncontacto, teléfono, telefono, correo, @",
      'handoff_trigger_phrases' => "asesor\npropuesta personalizada",
    ]);
    $bot->save();

    // Another bot talked to a contact with the same phone number later. The
    // legacy list attributed leads by phone and would pick this one.
    $other_bot = $storage('ai_whatsapp_bot')->create(['name' => 'Laboratorio', 'status' => 'active']);
    $other_bot->save();

    $conversation = $storage('ai_whatsapp_conversation')->create([
      'phone' => 'web:1:session',
      'name' => 'Web visitor',
      'channel' => 'web',
      'provider' => 'web',
      'status' => 'AI_ACTIVE',
      'bot' => $bot->id(),
    ]);
    $conversation->save();
    $this->addMessage($conversation, 'contact', 'Necesito una cotizacion');
    $this->addMessage($conversation, 'ai', "Cotización — información necesaria\n\n🏢 **Nombre de la empresa:**\n📦 **Tipo de mercancía:**\n📞 **Nombre y teléfono de contacto:**  \n📧 **Correo electrónico:**");
    $this->addMessage($conversation, 'contact', 'Jerotracker, guitarras, paracho Michoacán, a CDMX, terrestres, valor 50, 000 USD, tel. 5512309140, frecuencia 4 embarcaciones por mes');
    $ai_response = "Datos recibidos — falta información\n\n🏢 **Empresa:** Jerotracker\n📞 **Teléfono:** 55 1230 9140\n\n👤 **Nombre del contacto:**  \n📧 **Correo electrónico:**\n\n👉 Un asesor especializado elaborará una propuesta personalizada.";
    $this->addMessage($conversation, 'ai', $ai_response);

    $storage('ai_whatsapp_conversation')->create([
      'phone' => '+525512309140',
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
      'bot' => $other_bot->id(),
    ])->save();

    $result = $this->container->get('ai_whatsapp_automation.lead_handoff')->handle($conversation, $ai_response);

    $this->assertSame('created_without_recipients', $result['status']);
    $lead = $storage('ai_whatsapp_lead')->load($result['lead_id']);
    $this->assertInstanceOf(ContentEntityInterface::class, $lead);
    $this->assertSame((string) $conversation->id(), (string) $lead->get('conversation')->target_id);
    $this->assertSame((string) $bot->id(), (string) $lead->get('bot')->target_id);
    $this->assertSame('Nombre no capturado', $lead->get('name')->value);
    $this->assertSame('+525512309140', $lead->get('phone')->value);
    $this->assertSame('web', $lead->get('source')->value);
  }

  /**
   * Stores a message in a conversation.
   */
  private function addMessage(ContentEntityInterface $conversation, string $sender, string $content): void {
    $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_message')->create([
      'conversation' => $conversation->id(),
      'sender' => $sender,
      'content' => $content,
    ])->save();
  }

}
