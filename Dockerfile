FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl bash openssh-client ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Railway CLI — needed for `railway ssh`, the only way this dispatcher
# reaches Alex's geo-prospect worker (it has no web server; see
# lib/railway_exec.py for why `railway run` is NOT what we want here).
# Must run via bash explicitly: Docker's RUN uses /bin/sh (dash on
# Debian), and the installer uses bash-only ${...} substitution past the
# "installed successfully" point — piping into `sh` fails with
# "Bad substitution" after the binary is already in place.
RUN curl -fsSL https://railway.com/install.sh | bash
# The installer puts the binary at ~/.railway/bin/railway, which isn't on
# PATH for a non-interactive process (ENTRYPOINT doesn't source .bashrc).
ENV PATH="/root/.railway/bin:${PATH}"

# Pin the expected host key for ssh.railway.com so `railway ssh` never
# hits an interactive "are you sure you want to continue connecting"
# prompt in this non-interactive container — that prompt would just
# hang forever with no TTY to answer it. Fingerprint confirmed against
# the real host on 2026-07-15 (ssh.railway.com / 66.33.22.3,
# SHA256:+S1xg92FrnHz6pY3bpkmh1OGtWQGNANXilPzlxA7B1g).
RUN mkdir -p /root/.ssh && chmod 700 /root/.ssh && \
    echo 'ssh.railway.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ8X3z81/tuP7CvmK3ZqWgwEvHUR6b04oi2lQJGld2C1' \
        > /root/.ssh/known_hosts && \
    chmod 600 /root/.ssh/known_hosts

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
RUN chmod +x entrypoint.sh

ENTRYPOINT ["/app/entrypoint.sh"]
