<?php

namespace App\Modules\Vendors\Application;

use App\Modules\Vendors\Domain\InvalidVendorDetails;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorSlugAlreadyExists;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The only way to create a Vendor (Story #1; dependency-rules.md L8). It
 * creates the Vendor in `pending` and does nothing else: approval is a
 * separate lifecycle transition (VendorLifecycle), and no membership,
 * application or audit record is written (#29, owner decisions D3, D7).
 *
 * It is neither authenticated nor audited: it records no actor. Callers that
 * expose it must authorise first, once an operator identity exists.
 *
 * The database enforces slug uniqueness (`vendors_slug_unique`, case- and
 * accent-insensitive under the column's collation). There is no lookup first,
 * so concurrent creations of one slug cannot both succeed; the losing insert's
 * violation of that index alone becomes VendorSlugAlreadyExists (D2). It runs
 * on the default connection, inside the caller's transaction if there is one.
 */
final class CreateVendor
{
    /** D1: lowercase ASCII kebab-case, 3–80 characters, single inner hyphens. */
    public const SLUG_PATTERN = '/\A(?=.{3,80}\z)[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /** The `name` column is VARCHAR(255), counted in characters. */
    public const NAME_MAX_LENGTH = 255;

    private const SLUG_INDEX = 'vendors_slug_unique';

    /**
     * @param  string  $name  The display name. Surrounding whitespace is trimmed.
     * @param  string  $slug  The public identifier, stored exactly as given.
     *
     * @throws InvalidVendorDetails when the name or slug breaks its rule; nothing is written
     * @throws VendorSlugAlreadyExists when another Vendor holds an equivalent slug; nothing is written
     */
    public function handle(string $name, string $slug): Vendor
    {
        $name = trim($name);

        if ($name === '' || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > self::NAME_MAX_LENGTH) {
            throw InvalidVendorDetails::name(self::NAME_MAX_LENGTH);
        }

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw InvalidVendorDetails::slug();
        }

        try {
            return Vendor::query()->create(['slug' => $slug, 'name' => $name]);
        } catch (UniqueConstraintViolationException $exception) {
            if ($exception->index === self::SLUG_INDEX) {
                throw VendorSlugAlreadyExists::for($slug, $exception);
            }

            throw $exception;
        }
    }
}
