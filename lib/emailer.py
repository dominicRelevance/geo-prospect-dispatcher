"""Sends the finished PDF report via Mailjet's SMTP relay.

Mailjet SMTP: username is the API key, password is the API secret
(not a separate SMTP-specific credential) — https://in-v3.mailjet.com:587.
"""
from __future__ import annotations

import os
import smtplib
from email.mime.application import MIMEApplication
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText

MAILJET_SMTP_HOST = "in-v3.mailjet.com"
MAILJET_SMTP_PORT = 587


def send_report_email(to_email: str, client_name: str, pdf_bytes: bytes, pdf_filename: str) -> None:
    api_key = os.environ["MAILJET_API_KEY"]
    api_secret = os.environ["MAILJET_API_SECRET"]
    from_email = os.environ.get("MAILJET_FROM_EMAIL", "reports@relevance.digital")
    from_name = os.environ.get("MAILJET_FROM_NAME", "Relevance Digital")

    msg = MIMEMultipart()
    msg["Subject"] = f"Your AI Visibility Report is ready — {client_name}"
    msg["From"] = f"{from_name} <{from_email}>"
    msg["To"] = to_email

    body = (
        f"Hi,\n\nYour AI Visibility Report for {client_name} is attached.\n\n"
        f"Best,\n{from_name}"
    )
    msg.attach(MIMEText(body, "plain"))

    attachment = MIMEApplication(pdf_bytes, _subtype="pdf")
    attachment.add_header("Content-Disposition", "attachment", filename=pdf_filename)
    msg.attach(attachment)

    with smtplib.SMTP(MAILJET_SMTP_HOST, MAILJET_SMTP_PORT) as server:
        server.starttls()
        server.login(api_key, api_secret)
        server.sendmail(from_email, [to_email], msg.as_string())
