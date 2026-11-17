import sys
content = open('c:/Projects/UACP/app/data/bridge.py', 'r').read()
idx = content.find('def update_product(')
if idx != -1:
    clean = content[:idx] + '''def update_product(self, product_id: int, data: dict) -> bool:
        if self.client:
            return self.client.update_product(product_id, data)
        return False

    def create_product(self, data: dict) -> dict:
        """
        Wrapper to create a product. Returns full product data dict on success (including id), or None.
        """
        if self.client:
            return self.client.create_product(data)
        return None

    def get_category_id(self, name: str) -> int | None:
        """
        Finds a category ID by name (case-insensitive).
        """
        if not self.client:
            return None
            
        categories = self.client.get_categories()
        for cat in categories:
            if cat.get('name', '').lower() == name.lower():
                return cat.get('id')
        return None

    def complete_product_request(self, request_id: int, products: list, action: str = 'send_confirm') -> bool:
        """
        Wrapper to complete a product request.
        """
        if self.client:
            return self.client.complete_product_request(request_id, products, action=action)
        return False

    def ship_lines(self, order_id: int, payload: dict) -> bool:
        """
        Sends partial shipment payload to the API.
        """
        if self.client:
            return self.client.ship_lines(order_id, payload)
        return False

    def upload_image_async(self, path: str, sku: str) -> int | None:
        """
        Uploads an image, renaming it to {sku}.{ext}.
        """
        if not self.client:
            return None
            
        import os
        _, ext = os.path.splitext(path)
        new_name = f"{sku}{ext}"
        
        return self.client.upload_media(path, new_name)
'''
    open('c:/Projects/UACP/app/data/bridge.py', 'w').write(clean)
    print('Fixed bridge.py!')
else:
    print('Could not find marker')
