<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\WebChat;

use Drupal\ai_whatsapp_automation\Controller\WebChatController;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests what a client's website receives to draw the chat button.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class WebChatWidgetAppearanceTest extends KernelTestBase {

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
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('ai_whatsapp_knowledge_base');
    $this->installEntitySchema('ai_whatsapp_account');
    $this->installEntitySchema('ai_whatsapp_bot');
  }

  /**
   * The button picture is its own image, else the logo, else nothing.
   */
  public function testButtonImageFallsBackToTheLogo(): void {
    $logo = $this->file('logo-cliente.png');
    $button = $this->file('boton-cliente.png');
    $bot = $this->bot(['web_widget_icon' => 'image']);
    $web_chat = $this->container->get('ai_whatsapp_automation.web_chat');

    $this->assertSame('', $web_chat->widgetConfig($bot)['buttonImageUrl'], 'No picture at all: the launcher draws its chat icon.');

    $bot->set('web_widget_logo_file', $logo)->save();
    $this->assertStringEndsWith('/logo-cliente.png', $web_chat->widgetConfig($bot)['buttonImageUrl'], 'Only a logo: the button uses it.');

    $bot->set('web_widget_button_image', $button)->save();
    $config = $web_chat->widgetConfig($bot);
    $this->assertStringEndsWith('/boton-cliente.png', $config['buttonImageUrl'], 'Its own picture wins over the logo.');
    $this->assertStringEndsWith('/logo-cliente.png', $config['logoUrl'], 'The chat header keeps the logo.');
  }

  /**
   * A colour that is not a hex colour never reaches a client's website.
   *
   * The form refuses it, but a value saved before that check existed, or by
   * code, would otherwise be printed into CSS on a page we do not control.
   */
  public function testStoredInvalidColourIsNeverServed(): void {
    $bot = $this->bot([
      'web_widget_primary_color' => 'red;top:0',
      'web_widget_secondary_color' => 'url(x)',
    ]);

    $config = $this->container->get('ai_whatsapp_automation.web_chat')->widgetConfig($bot);
    $this->assertSame('#155EEF', $config['primaryColor']);
    $this->assertSame('#111827', $config['secondaryColor']);

    $embed = $this->embed($bot);
    $this->assertStringNotContainsString('red;top', $embed);
    $this->assertStringContainsString('"primaryColor":"#155EEF"', $embed);
  }

  /**
   * The bot refuses to be saved with a colour that is not hex.
   */
  public function testColourFieldsOnlyAcceptHexColours(): void {
    $bot = $this->bot([]);

    foreach (['#7B2D8E', '#fff', '#FDE68A'] as $valid) {
      $bot->set('web_widget_primary_color', $valid);
      $this->assertCount(0, $bot->validate()->getByField('web_widget_primary_color'), $valid);
    }
    foreach (['morado', '#12345', 'red;top:0', '#7B2D8E80'] as $invalid) {
      $bot->set('web_widget_primary_color', $invalid);
      $this->assertCount(1, $bot->validate()->getByField('web_widget_primary_color'), $invalid);
      $bot->set('web_widget_secondary_color', $invalid);
      $this->assertCount(1, $bot->validate()->getByField('web_widget_secondary_color'), $invalid);
      $bot->set('web_widget_secondary_color', '#111827');
    }
  }

  /**
   * The embed carries the painter and everything it needs, in one response.
   *
   * Clients pasted a single script tag; splitting the painter into a second
   * request would break the sites that already have it.
   */
  public function testEmbedServesThePainterWithTheConfiguration(): void {
    $bot = $this->bot([
      'web_widget_icon' => 'sparkles',
      'web_widget_primary_color' => '#7B2D8E',
      'web_widget_button_image' => $this->file('boton.png'),
    ]);

    $embed = $this->embed($bot);
    $configuration = json_decode(substr($embed, strlen('window.AIWhatsAppAutomationWidget='), strpos($embed, ';') - strlen('window.AIWhatsAppAutomationWidget=')), TRUE);

    $this->assertSame('sparkles', $configuration['icon']);
    $this->assertSame('#7B2D8E', $configuration['primaryColor']);
    $this->assertStringEndsWith('/boton.png', $configuration['buttonImageUrl']);
    $this->assertStringContainsString('root.AIWhatsAppAutomationLauncher', $embed, 'The painter is served.');
    $this->assertStringContainsString('AIWhatsAppAutomationLauncher.paint(button, config)', $embed, 'The mount script uses it.');
    $this->assertLessThan(strpos($embed, 'AIWhatsAppAutomationLauncher.paint(button'), strpos($embed, 'root.AIWhatsAppAutomationLauncher'), 'The painter is defined before the mount script calls it.');
  }

  /**
   * Creates a saved bot with the given values.
   *
   * @param array<string, mixed> $values
   *   Field values.
   */
  private function bot(array $values): ContentEntityInterface {
    $bot = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot')->create($values + [
      'name' => 'JG Mylard',
      'status' => 'active',
      'web_widget_enabled' => TRUE,
    ]);
    $bot->save();

    return $bot;
  }

  /**
   * Creates a saved public file entity.
   */
  private function file(string $name): File {
    file_put_contents('public://' . $name, 'png');
    $file = File::create(['uri' => 'public://' . $name, 'status' => 1]);
    $file->save();

    return $file;
  }

  /**
   * Returns the embed script a client's website receives for a bot.
   */
  private function embed(ContentEntityInterface $bot): string {
    $controller = WebChatController::create($this->container);
    $response = $controller->embed(Request::create('https://app.josera.com.mx/ai-whatsapp-automation/embed/x'), (string) $bot->uuid());
    $this->assertSame(200, $response->getStatusCode());

    return (string) $response->getContent();
  }

}
