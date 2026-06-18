<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\RAG;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Symfony\Component\Process\Process;

/**
 * Extracts text from supported knowledge documents.
 */
final class DocumentParserService {

  /**
   * Constructs a DocumentParserService object.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {
  }

  /**
   * Extracts text from a file entity.
   */
  public function parse(FileInterface $file): string {
    $path = $this->fileSystem->realpath($file->getFileUri());
    if ($path === FALSE || !is_readable($path)) {
      throw new \RuntimeException('The uploaded document cannot be read.');
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $text = match ($extension) {
      'txt' => $this->parseText($path),
      'docx' => $this->parseDocx($path),
      'pdf' => $this->parsePdf($path),
      default => throw new \RuntimeException('Unsupported document type.'),
    };

    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
      throw new \RuntimeException('No text could be extracted from the document.');
    }

    return $text;
  }

  /**
   * Parses plain text files.
   */
  private function parseText(string $path): string {
    $contents = file_get_contents($path);

    return $contents === FALSE ? '' : $contents;
  }

  /**
   * Parses DOCX files using their document XML.
   */
  private function parseDocx(string $path): string {
    $zip = new \ZipArchive();
    if ($zip->open($path) !== TRUE) {
      throw new \RuntimeException('The DOCX document could not be opened.');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === FALSE) {
      throw new \RuntimeException('The DOCX document does not contain readable document text.');
    }

    $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;

    return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
  }

  /**
   * Parses PDF files through pdftotext when available.
   */
  private function parsePdf(string $path): string {
    $probe = Process::fromShellCommandline('command -v pdftotext');
    $probe->run();
    if (!$probe->isSuccessful() || trim($probe->getOutput()) === '') {
      throw new \RuntimeException('PDF parsing requires the pdftotext binary to be installed on the server.');
    }

    $process = new Process(['pdftotext', '-layout', $path, '-']);
    $process->setTimeout(60);
    $process->run();

    if (!$process->isSuccessful()) {
      throw new \RuntimeException('The PDF document could not be parsed.');
    }

    return $process->getOutput();
  }

}
