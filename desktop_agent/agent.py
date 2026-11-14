#!/usr/bin/env python3
"""FP-OMS RED desktop PNPKI signer (interactive Windows CLI pilot).

No local PDF download/upload folder is used. The unsigned PDF arrives in memory;
the signed PDF is posted directly back to FP-OMS. The PNPKI .p12 private key
and passphrase never leave this workstation. There is no unattended signing.
"""
from __future__ import annotations

import argparse
import getpass
import hashlib
import io
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


class SigningError(Exception):
    pass


def endpoint(base: str, action: str, job_id: int | None = None) -> str:
    path = "pnpki-desktop-agent-api"
    query = urllib.parse.urlencode({"action": action, **({"id": job_id} if job_id else {})})
    return urllib.parse.urljoin(base.rstrip("/") + "/", path) + "?" + query


def assert_secure_base(base: str, allow_local_http: bool) -> None:
    parts = urllib.parse.urlparse(base)
    if parts.scheme == "https" and parts.hostname and not (parts.username or parts.password):
        return
    if (allow_local_http and parts.scheme == "http"
            and parts.hostname in ("localhost", "127.0.0.1")
            and not (parts.username or parts.password)):
        return
    raise SigningError("Only HTTPS is allowed. For a local XAMPP pilot only, "
                       "use --allow-local-http with a localhost/127.0.0.1 URL.")


def call(base: str, token: str, action: str, job_id: int | None = None,
         pdf: bytes | None = None) -> tuple[bytes, dict[str, str]]:
    headers = {
        "Authorization": "Bearer " + token,
        "Accept": "application/pdf" if action == "draft" else "application/json",
        "Cache-Control": "no-store",
    }
    if pdf is not None:
        headers["Content-Type"] = "application/pdf"
    request = urllib.request.Request(
        endpoint(base, action, job_id),
        data=pdf,
        headers=headers,
        method="POST" if pdf is not None else "GET",
    )
    try:
        with urllib.request.urlopen(request, timeout=45) as response:
            limit = 11 * 1024 * 1024
            blob = response.read(limit + 1)
            if len(blob) > limit:
                raise SigningError("Server response exceeds 11 MB.")
            return blob, dict(response.headers.items())
    except urllib.error.HTTPError as exc:
        message = f"FP-OMS rejected {action} (HTTP {exc.code})"
        try:
            detail = json.loads(exc.read(2048).decode("utf-8")).get("error")
            if isinstance(detail, str) and detail:
                message += ": " + detail[:400]
        except (ValueError, UnicodeError, OSError):
            pass
        raise SigningError(message) from exc
    except urllib.error.URLError as exc:
        raise SigningError("FP-OMS connection failed. Check HTTPS/VPN/server connectivity.") from exc


def approve_and_sign(unsigned: bytes, expected_sha256: str, pfx_file: Path, job: dict) -> bytes:
    if hashlib.sha256(unsigned).hexdigest() != expected_sha256:
        raise SigningError("Draft SHA-256 does not match the signed queue request.")
    if not unsigned.startswith(b"%PDF-"):
        raise SigningError("Server did not provide a PDF.")
    job_id = int(job["id"])
    print("\nDocument to sign:")
    print(f"  FP application: #{job['application_id']}")
    print(f"  Document:       {job['description']}")
    print(f"  SHA-256:        {expected_sha256}")
    print("Review this exact document in FP-OMS before approving.")
    confirmation = input(f"Type SIGN {job_id} to apply your personal PNPKI signature: ").strip()
    if confirmation != f"SIGN {job_id}":
        raise SigningError("Signing cancelled by RED. Nothing was submitted.")
    passphrase = getpass.getpass("PNPKI .p12 password (not sent to FP-OMS): ").encode("utf-8")
    try:
        from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
        from pyhanko.sign import signers

        signer = signers.SimpleSigner.load_pkcs12(str(pfx_file), passphrase=passphrase)
        if signer is None:
            raise SigningError("Unable to unlock the .p12 certificate.")
        # BytesIO keeps the unsigned and signed PDFs in memory; no manual files.
        stream = io.BytesIO(unsigned)
        writer = IncrementalPdfFileWriter(stream)
        signed_buffer = signers.sign_pdf(
            writer,
            signers.PdfSignatureMetadata(field_name="RED_PNPKI_Signature"),
            signer=signer,
        )
        signed = signed_buffer.getvalue()
    finally:
        del passphrase
    if not signed.startswith(unsigned) or b"/ByteRange" not in signed:
        raise SigningError("Signed PDF did not preserve the unsigned source.")
    return signed


def run() -> int:
    parser = argparse.ArgumentParser(description="FP-OMS interactive RED PNPKI desktop agent")
    parser.add_argument("--url", required=True, help="FP-OMS public/ URL, e.g. https://fpoms.example.gov.ph/")
    parser.add_argument("--p12", required=True, type=Path, help="RED's local PNPKI PKCS#12 certificate file")
    parser.add_argument("--allow-local-http", action="store_true", help="Localhost XAMPP development only")
    parser.add_argument("--once", action="store_true", help="Check the queue just once and exit")
    args = parser.parse_args()

    assert_secure_base(args.url, args.allow_local_http)
    if not args.p12.is_file() or args.p12.suffix.lower() not in (".p12", ".pfx"):
        raise SigningError("Select the RED's existing local .p12 or .pfx certificate.")
    token = getpass.getpass("Paste the one-time RED workstation pairing token: ").strip()
    if not re.fullmatch("[a-f0-9]{64}", token):
        raise SigningError("Invalid pairing token format.")
    print("Paired session started. Certificate password is requested separately for EVERY signature.")
    print("Ctrl+C exits. The agent never signs documents without RED's confirmation.")
    while True:
        raw, _ = call(args.url, token, "next")
        try:
            job = json.loads(raw.decode("utf-8")).get("job")
        except (UnicodeError, ValueError, AttributeError) as exc:
            raise SigningError("Invalid FP-OMS job response.") from exc
        if job:
            try:
                job_id = int(job["id"])
                unsigned, headers = call(args.url, token, "draft", job_id)
                expected_sha = str(job["sha256"])
                server_sha = next((v for k, v in headers.items()
                                   if k.lower() == "x-document-sha256"), "")
                if expected_sha != server_sha:
                    raise SigningError("Draft response hash header does not match queued job.")
                signed = approve_and_sign(unsigned, expected_sha, args.p12, job)
                receipt, _ = call(args.url, token, "submit", job_id, signed)
                result = json.loads(receipt.decode("utf-8"))
                if result.get("ok"):
                    print("Signed PDF delivered to PNPKI_2 Records. Await official Records release.")
                else:
                    raise SigningError("FP-OMS did not acknowledge the signed PDF.")
            except SigningError as exc:
                print("Not submitted: " + str(exc), file=sys.stderr)
            except Exception as exc:
                # Never dump certificate, password, token, PDF data, or signing internals.
                print("Signing failed. Check certificate format and agent dependencies.",
                      file=sys.stderr)
            finally:
                # No signature/PDF is persisted on disk by this agent.
                pass
        elif args.once:
            print("No eligible signing job.")
        if args.once:
            return 0
        time.sleep(10)


if __name__ == "__main__":
    try:
        raise SystemExit(run())
    except KeyboardInterrupt:
        print("\nAgent stopped.")
    except SigningError as error:
        print("Error: " + str(error), file=sys.stderr)
        raise SystemExit(1)
