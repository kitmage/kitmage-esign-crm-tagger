# Kitmage E-Sign CRM Tagger

A small WordPress plugin that applies **FluentCRM tags** to **logged-in WordPress users** when they sign mapped **ApproveMe WP E-Signature** documents.

## Status

**Version 0.1.1 — email-only identity matching; needs end-to-end validation on your installed ApproveMe version before production use.**

## Requirements

- WordPress and PHP 7.4+
- ApproveMe WP E-Signature
- FluentCRM
- Signing requests must execute while the intended signer is logged into their own WordPress account.

## Installation

1. Download the repository as ZIP.
2. Upload under **Plugins → Add New → Upload Plugin** and activate **Kitmage E-Sign CRM Tagger**.
3. Open **Tools → E-Sign CRM Tagger**.
4. Enter document-to-tag mappings, one per line:

   ```text
   123: 24
   456: 16, 32
   ```

5. Save. Document 123 now grants tag 24; document 456 grants tags 16 and 32.

Use the **original stand-alone document ID** for stand-alone documents, rather than the cloned document ID generated for each new signature. For basic documents, use their own document ID.

Duplicate entries for a document are combined.

## Behavior

- Listens to `esig_signature_saved` and `esig_document_basic_closing`.
- Requires a logged-in WordPress user with a valid account email. **The WordPress account email is the only source of signer identity.** ApproveMe signer email/ID are never read or compared.
- Uses the ApproveMe event's `sad_doc_id` when supplied, otherwise its invitation's `document_id`.
- Resolves the FluentCRM contact using `FluentCrmApi('contacts')->getContact($email)`, with the email sourced exclusively from the logged-in WordPress account; it does not look up contacts by WordPress user ID.
- If no matching email contact exists, creates a **transactional** contact using that email; it **does not opt the user into marketing emails** and does not explicitly associate the new contact with a WordPress user ID.
- Adds only tags not already present on the contact. The two supported signing hooks do not cause duplicate tag applications during a single request.
- Does nothing for unmapped documents, visitors, invalid/missing WordPress account email addresses, or when FluentCRM is unavailable.
- Does not remove tags, poll document status, or retroactively process old signatures.

There is no public tagging endpoint or redirect-based tag grant.

## Validation checklist on staging

1. Make a test document and mapping with an existing FluentCRM tag ID.
2. Log in as a test signer and sign. Confirm that the FluentCRM contact whose **email equals the logged-in WordPress user's email** receives the tag.
3. Repeat with a stand-alone document and verify that the mapping uses the *original* document ID.
4. Repeat with a basic document. Ensure both signing hooks firing does not cause duplicate automation behavior.
5. Confirm unmapped documents add no tags.
6. Test a user without a FluentCRM contact; the contact should be created with transactional status.
7. Test a mismatched ApproveMe signer email and current WordPress login. The plugin intentionally **ignores ApproveMe identity fields** and targets the contact matching the logged-in account email. Confirm that the signing request always runs under the intended signer's session.
8. Check your FluentCRM automation triggers if you automate on tag additions.

## Known compatibility boundary

The hook names and event payload were verified against existing integration code, including [WP Fusion's ApproveMe integration](https://github.com/kingfunnel/wp-fusion/blob/master/includes/integrations/class-e-signature.php) and an older [WP E-Signature implementation](https://github.com/dev-rathankumar/byConcept-Cafe/blob/master/wp-content/plugins/e-signature/lib/Shortcode.php). ApproveMe is a commercial plugin, so the precise behavior in your installed release has **not** yet been verified.

This plugin uses the **logged-in account email only** for identity. It intentionally does not check ApproveMe's signer identity; a signing hook executed under the wrong logged-in account would target that account's email instead. Verify your signing workflow does not run signature callbacks in an administrator's or another user's session.

## Developer extension point

After new tags are successfully added:

```php
do_action(
    'kitmage_esign_crm_tagger/tags_added',
    $user_id,
    $document_id,
    $added_tag_ids,
    $signature_id
);
```

Use this for logging or other server-side follow-up. Do not use its existence as proof that a document has been signed by *all* parties.

## License

No license selected yet.
