# DFD Visual Download Troubleshooting Guide

## Quick Test

Open your browser's Developer Console (F12) and run:

```javascript
// Test if functions are loaded
console.log('downloadDiagram:', typeof downloadDiagram);
console.log('downloadDiagramSVG:', typeof downloadDiagramSVG);
console.log('downloadAllDiagrams:', typeof downloadAllDiagrams);

// Test if SVG exists
const svg = document.querySelector('#context-diagram svg');
console.log('Context diagram SVG found:', !!svg);
```

## Common Issues and Solutions

### Issue 1: Downloads Not Starting
**Symptoms:** Clicking download buttons does nothing

**Solutions:**
1. Check browser console for errors (F12)
2. Ensure diagrams are fully loaded (wait 2-3 seconds after page load)
3. Check browser's download settings - downloads may be blocked
4. Try right-clicking the download button and "Inspect Element" to see if click event is attached

### Issue 2: SVG Downloads Work but PNG Doesn't
**Symptoms:** SVG downloads fine, PNG shows error or blank image

**Solutions:**
1. Use SVG format instead (better quality anyway)
2. Check browser console for CORS errors
3. Try a different browser (Chrome, Firefox, Edge)
4. Ensure browser allows downloads from this domain

### Issue 3: "Diagram not yet loaded" Error
**Symptoms:** Alert says diagram isn't loaded

**Solutions:**
1. Wait 3-5 seconds after page loads
2. Refresh the page
3. Check browser console to see if Mermaid is loading properly
4. Ensure internet connection is active (Mermaid loads from CDN)

### Issue 4: Empty or Broken Downloads
**Symptoms:** File downloads but is empty or corrupted

**Solutions:**
1. Check file size - if it's 0 bytes, the diagram wasn't captured
2. Try downloading as SVG first (more reliable)
3. Check browser's download permissions
4. Disable browser extensions that might interfere

## Manual Download Methods

### Method 1: Right-Click Save
1. Right-click on any diagram
2. Select "Inspect Element" or "Inspect"
3. Find the `<svg>` element in the HTML
4. Right-click on the SVG element
5. Some browsers allow "Save Image As"

### Method 2: Screenshot
1. Use browser's screenshot tool (or Windows Snipping Tool)
2. Capture the diagram area
3. Save as PNG/JPG

### Method 3: Browser Print to PDF
1. Press Ctrl+P (Cmd+P on Mac)
2. Select "Save as PDF"
3. Choose "More settings" and set margins to minimum
4. Print to save entire page or selected area

## Browser Compatibility

- ✅ Chrome/Edge: Full support
- ✅ Firefox: Full support  
- ✅ Safari: SVG works, PNG may have issues
- ⚠️ Older browsers: May not work properly

## Debugging Steps

1. **Open Console (F12)**
   - Look for red error messages
   - Check if functions are defined

2. **Check Diagram Loading**
   ```javascript
   // In console, check if diagrams are loaded
   document.querySelectorAll('.mermaid svg').length
   // Should return number of diagrams (at least 2)
   ```

3. **Test Single Download**
   ```javascript
   // Try downloading context diagram
   downloadDiagramSVG('context-diagram', 'test');
   ```

4. **Check Browser Downloads**
   - Open browser's download manager
   - See if downloads are being blocked
   - Check download folder permissions

## Still Having Issues?

1. Try downloading SVG format first (more reliable)
2. Use a different browser
3. Check browser console for specific error messages
4. Ensure you're opening the HTML file directly (file://) or via a web server

