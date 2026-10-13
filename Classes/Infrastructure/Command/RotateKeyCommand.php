<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Command;

use Koh\ContentProvenance\Domain\Contract\KeyProviderInterface;
use Koh\ContentProvenance\Domain\Exception\KeyRotationNotSupportedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'provenance:rotate-key',
    description: 'Rotate the signing key',
)]
final class RotateKeyCommand extends Command
{
    public function __construct(
        private readonly KeyProviderInterface $keyProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rotate Signing Key');

        try {
            $oldKeyId = $this->keyProvider->getCurrentKeyId();
            $newKeyPair = $this->keyProvider->rotateKey();

            $io->success(sprintf(
                'Key rotated: %s -> %s',
                $oldKeyId,
                $newKeyPair->keyId,
            ));

            return Command::SUCCESS;
        } catch (KeyRotationNotSupportedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
