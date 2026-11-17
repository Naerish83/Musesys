# MuseSys-Mega Unified Admin Control Panel (UACP)

## Overview
The UACP is a local-side Python GUI application designed to manage the MuseSys-Mega plugin ecosystem. It provides a unified interface for viewing and editing custom fields across Orders, Users, Products, and Requests.

## Installation

1.  **Prerequisites**:
    *   Python 3.8+
    *   `pip`

2.  **Install Dependencies**:
    ```bash
    pip install requests pydantic tk
    ```

## Configuration

1.  **WordPress API**:
    *   Ensure the `MuseSys API` module is active in WordPress.
    *   Create an Application Password for your user in WordPress (Users > Profile > Application Passwords).

2.  **Local Settings**:
    *   (Future) The app will prompt for URL/Credentials on first run or via a settings file.

## Running the App

Run the `main.py` script:

```bash
python main.py
```

## Features

*   **Order Ops**: Search orders by ID, view/edit Work Order #, Store #, etc.
*   **Validation**: Enforces 7-digit Work Orders and 3-digit Store Numbers.
*   **Local Cache**: Data is cached locally in the `data/` directory for offline work (mocked for now).

## Project Structure

*   `uacp/`
    *   `main.py`: Entry point.
    *   `models.py`: Pydantic data models.
    *   `api_client.py`: WordPress REST API client.
    *   `data/`: JSON schema and local data storage.
    *   `gui/`: Tkinter GUI code.
        *   `app.py`: Main window.
        *   `views/`: Individual screens (e.g., OrderOps).
    *   `../modules/musesys-api/`: Server-side PHP API plugin.
