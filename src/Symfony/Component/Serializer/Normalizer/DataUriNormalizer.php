<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Serializer\Normalizer;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;

/**
 * Normalizes an {@see \SplFileInfo} object to a data URI.
 * Denormalizes a data URI to a {@see \SplFileObject} object.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class DataUriNormalizer implements NormalizerInterface, DenormalizerInterface
{
    private const SUPPORTED_TYPES = [
        \SplFileInfo::class => true,
        \SplFileObject::class => true,
        File::class => true,
    ];

    private readonly ?MimeTypeGuesserInterface $mimeTypeGuesser;

    public function __construct(?MimeTypeGuesserInterface $mimeTypeGuesser = null)
    {
        if (!$mimeTypeGuesser && class_exists(MimeTypes::class)) {
            $mimeTypeGuesser = MimeTypes::getDefault();
        }

        $this->mimeTypeGuesser = $mimeTypeGuesser;
    }

    public function getSupportedTypes(?string $format): array
    {
        return self::SUPPORTED_TYPES;
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        if (!$data instanceof \SplFileInfo) {
            throw new InvalidArgumentException('The object must be an instance of "\SplFileInfo".');
        }

        $mimeType = $this->getMimeType($data);
        $splFileObject = $this->extractSplFileObject($data);

        $splFileData = '';

        $splFileObject->rewind();
        while (!$splFileObject->eof()) {
            $splFileData .= $splFileObject->fgets();
        }

        if ('text' === explode('/', $mimeType, 2)[0]) {
            return \sprintf('data:%s,%s', $mimeType, rawurlencode($splFileData));
        }

        return \sprintf('data:%s;base64,%s', $mimeType, base64_encode($splFileData));
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof \SplFileInfo;
    }

    /**
     * Regex adapted from Brian Grinstead code.
     *
     * @see https://gist.github.com/bgrins/6194623
     *
     * @throws InvalidArgumentException
     * @throws NotNormalizableValueException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): \SplFileInfo
    {
        if (null === $data || !preg_match('/^data:([a-z0-9][a-z0-9\!\#\$\&\-\^\_\+\.]{0,126}\/[a-z0-9][a-z0-9\!\#\$\&\-\^\_\+\.]{0,126}(;[a-z0-9\-]+\=[a-z0-9\-]+)?)?(;base64)?,[a-z0-9\!\$\&\\\'\,\(\)\*\+\;\=\-\.\_\~\:\@\/\?\%\s]*\s*$/i', $data)) {
            throw NotNormalizableValueException::createForUnexpectedDataType('The provided "data:" URI is not valid.', $data, ['string'], $context['deserialization_path'] ?? null, true);
        }

        try {
            switch ($type) {
                case File::class:
                    if (!class_exists(File::class)) {
                        throw new InvalidArgumentException(\sprintf('Cannot denormalize to a "%s" without the HttpFoundation component installed. Try running "composer require symfony/http-foundation".', File::class));
                    }

                    return new File($data, false);

                case 'SplFileObject':
                case 'SplFileInfo':
                    return new \SplFileObject($data);
            }
        } catch (\RuntimeException $exception) {
            throw NotNormalizableValueException::createForUnexpectedDataType($exception->getMessage(), $data, ['string'], $context['deserialization_path'] ?? null, false, $exception->getCode(), $exception);
        }

        throw new InvalidArgumentException(\sprintf('The class parameter "%s" is not supported. It must be one of "SplFileInfo", "SplFileObject" or "Symfony\Component\HttpFoundation\File\File".', $type));
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return isset(self::SUPPORTED_TYPES[$type]);
    }

    /**
     * Gets the mime type of the object. Defaults to application/octet-stream.
     */
    private function getMimeType(\SplFileInfo $object): string
    {
        if ($object instanceof File) {
            return $object->getMimeType();
        }

        return $this->mimeTypeGuesser?->guessMimeType($object->getPathname()) ?: 'application/octet-stream';
    }

    /**
     * Returns the \SplFileObject instance associated with the given \SplFileInfo instance.
     */
    private function extractSplFileObject(\SplFileInfo $object): \SplFileObject
    {
        if ($object instanceof \SplFileObject) {
            return $object;
        }

        return $object->openFile();
    }
}
