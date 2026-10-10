"""Restore fictional mail in the isolated GreenMail; never connects externally."""
import email.message
import imaplib
import secrets

HOST = "test-mail"
ADDRESS = "fixture@plmail.test"
FOLDERS = ["INBOX", "Sent", "Archive", "Projects", "Spam", "Trash", "Drafts"]

mail = imaplib.IMAP4(HOST, 3143)
mail.login(ADDRESS, secrets.token_hex(16))
try:
    for folder in FOLDERS:
        if folder != "INBOX":
            mail.create(folder)
        status, _ = mail.select(folder)
        if status != "OK":
            raise RuntimeError("Cannot select synthetic mailbox folder")
        fixtures = [("Synthetic " + folder, "greenmail-" + folder.lower())]
        if folder == "INBOX":
            fixtures.append(("Synthetic local SMTP delivery", "greenmail-local-delivery"))
        if folder == "Projects":
            fixtures.append(("Synthetic INBOX", "greenmail-inbox"))
        for subject, identity in fixtures:
            message_id = "<" + identity + "@fixture.test>"
            status, matches = mail.search(None, "HEADER", "Message-ID", message_id)
            if status != "OK":
                raise RuntimeError("Cannot inspect existing synthetic fixtures")
            if matches[0]:
                continue
            message = email.message.EmailMessage()
            message["From"] = "correspondent@fixture.test"
            message["To"] = ADDRESS
            message["Subject"] = subject
            message["Message-ID"] = message_id
            message.set_content("Fictional local-only message in " + folder)
            flags = r"(\Draft)" if folder == "Drafts" else None
            if mail.append(folder, flags, None, message.as_bytes())[0] != "OK":
                raise RuntimeError("Cannot append synthetic fixture")
    print("Synthetic IMAP fixtures ready; no SMTP send was performed.")
finally:
    mail.logout()
