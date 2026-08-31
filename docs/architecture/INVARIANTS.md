# System Invariants

These are properties that must remain true regardless of implementation details.

They represent architectural constraints rather than feature requirements.

---

## Vendor Isolation

Vendor-context queries must only expose records belonging to the
authenticated vendor.

Storefront catalog queries are intentionally not vendor-scoped.

---

## Marketplace Identity

A shopper has one marketplace-wide identity.

A vendor-team user belongs to exactly one vendor.

A platform operator does not belong to a vendor.

---

## Order Structure

An `OrderGroup` represents one shopper checkout.

An `Order` represents exactly one vendor's portion of that checkout.

An order must never contain products belonging to multiple vendors.

---

## Payment Ownership

An `OrderGroup` has exactly one PaymentIntent for its total.

---

## Refund Isolation

A refund against one vendor's `Order` must never alter the financial state
of another vendor's `Order`.

---

## Commission

The commission rate applied to an order is frozen at order creation.

Changing the platform commission configuration must not alter historical orders.

---

## Inventory

Inventory must never be oversold.

Stock reservation and release operations must be concurrency-safe.

---

## Vendor Visibility

A vendor's products are sellable only when the vendor satisfies the required
marketplace approval and payment-onboarding conditions.

---

## Auditability

Money-moving operations and material vendor-state changes must produce an
auditable record.

---

## Documentation

Boundary-shaping architectural decisions must have an ADR.

Changes to product scope must update the corresponding product documentation.