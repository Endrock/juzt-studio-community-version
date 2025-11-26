/**
 * Juzt Studio - Template Builder for WordPress
 * Vanilla JavaScript - No jQuery
 */

(function () {
  "use strict";

  // ==========================================
  // ESTADO DE LA APLICACIÓN
  // ==========================================

  const state = {
    templates: [],
    templatesBySource: null,
    selectedTemplate: null,
    availableSections: [],
    sectionSchemas: {},
    sectionsBySource: null,
    availablePostTypes: [], // NUEVO
    generalSettings: {}, // NUEVO: Settings generales
    generalSettingsSchema: null, // NUEVO: Schema de settings generales
    loading: true,
    currentMessage: null,
    expandedSections: {},
    activeTabs: {},
    previewMode: false, // NUEVO: Modo preview
    previewRefreshTimeout: null, // NUEVO: Para debounce del preview
  };

  // ==========================================
  // CONFIGURACIÓN
  // ==========================================

  const config = {
    ajaxUrl: window.sectionsBuilderData?.ajaxUrl || "",
    nonce: window.sectionsBuilderData?.nonce || "",
    timberEnabled: window.sectionsBuilderData?.timberEnabled || false,
    previewDelay: 1000,
  };

  // ==========================================
  // REFERENCIAS DOM
  // ==========================================

  const dom = {
    app: null,
    closeApp: null,
    templatesList: null,
    sectionsList: null,
    editor: null,
    message: null,
    loading: null,
    saveButton: null,
    clearCacheButton: null,
    templateTitle: null,
    builder: null,
    previewFrame: null, // NUEVO: iframe de preview
    previewToggle: null, // NUEVO: botón toggle preview
    settingsPanel: null, // NUEVO: panel de settings generales
  };

  // ==========================================
  // INICIALIZACIÓN
  // ==========================================

  function init() {
    // Esperar a que el DOM esté listo
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", initApp);
    } else {
      initApp();
    }
  }

  function initApp() {
    createAppStructure();
    cacheDOMElements();
    registerEvents();
    loadInitialData();
  }

  // Cachear elementos DOM
  function cacheDOMElements() {
    dom.app = document.getElementById("juzt-studio-app");
    dom.closeApp = document.querySelector(".juzt-studio-close-app");
    dom.templatesList = document.getElementById("js-templates-list");
    dom.sectionsList = document.getElementById("js-sections-list");
    dom.editor = document.getElementById("js-editor");
    dom.message = document.getElementById("js-message");
    dom.loading = document.getElementById("js-loading");
    dom.saveButton = document.getElementById("js-save-template");
    dom.templateTitle = document.getElementById("js-template-title");
    dom.builder = document.getElementById("js-builder");
    dom.previewFrame = document.getElementById("js-preview-frame");
    dom.previewToggle = document.getElementById("js-preview-toggle");
    dom.settingsPanel = document.getElementById("js-settings-panel");
    dom.clearCacheButton = document.querySelector(".juzt-studio-clear-cache");
  }

  // Cargar datos iniciales
  async function loadInitialData() {
    setLoading(true);

    try {
      await Promise.all([
        loadTemplates(),
        loadAvailableSections(),
        loadSectionSchemas(),
        loadGeneralSettingsSchema(), // NUEVO
        loadGeneralSettings(), // NUEVO
        loadPostTypes(), //Nuevo
      ]);

      setLoading(false);
    } catch (error) {
      console.error("Error loading initial data:", error);
      showMessage("error", "Error loading application: " + error.message);
      setLoading(false);
    }
  }

  function createAppStructure() {
    const app = document.getElementById("juzt-studio-app");
    if (!app) {
      console.error("Element #juzt-studio-app not found");
      return;
    }

    const timberStatus = config.timberEnabled
      ? '<div class="js-timber-status js-timber-enabled">✓ Timber/Twig enabled</div>'
      : '<div class="js-timber-status js-timber-disabled">⚠ Timber/Twig not detected</div>';

    const structure = `
    <!-- Mensajes -->
    <div id="js-message" class="js-message" style="display: none;"></div>
    
    <!-- Loading -->
      <div id="js-loading" class="js-loading">
      <p>Loading Juzt Studio...</p>
      ${timberStatus}
    </div>
    
    <!-- Builder Principal -->
    <div id="js-builder" class="js-builder" style="display: none;">
      
      <!-- Sidebar -->
      <div class="js-sidebar">
        
        <!-- Pestañas del Sidebar -->
        <div class="js-sidebar-tabs">
          <button class="js-tab-btn active" data-tab="templates">Templates</button>
          <button class="js-tab-btn" data-tab="sections">Sections</button>
          <button class="js-tab-btn" data-tab="settings">Settings</button>
        </div>
        
        <!-- Panel de Plantillas -->
        <div class="js-panel js-tab-content active" data-tab-content="templates">
          <h3 class="js-panel-title">Available Templates</h3>
          <div class="js-panel-content">
            <ul id="js-templates-list" class="js-list"></ul>
            <div style="margin-top: 15px">
              <button id="js-new-template" class="js-button js-button-primary">
                New Template
              </button>
            </div>
          </div>
        </div>
        
        <!-- Panel de Secciones -->
        <div class="js-panel js-tab-content" data-tab-content="sections">
          <h3 class="js-panel-title">Available Twig Sections</h3>
          <div class="js-panel-content">
            <ul id="js-sections-list" class="js-list"></ul>
            <div class="js-sections-info">
              <small class="js-help-text">
                Templates in <code>views/sections/</code><br>
                ⚙️ = Has schema | 📄 = No schema
              </small>
            </div>
          </div>
        </div>
        
        <!-- Panel de Settings Generales - NUEVO -->
        <div class="js-panel js-tab-content" data-tab-content="settings">
          <h3 class="js-panel-title">General Settings</h3>
          <div class="js-panel-content">
            <div id="js-settings-panel">
              <p class="js-help-text">Global theme settings</p>
            </div>
          </div>
        </div>
        
      </div>
      
      <!-- Contenido Principal -->
      <div class="js-content">
        
        <!-- Header -->
        <div class="js-header">
          <h2 id="js-template-title">Juzt Studio - Template Builder</h2>
          <div class="js-header-actions">
            <!-- Toggle Preview - NUEVO -->
            <button id="js-preview-toggle" class="js-button js-button-secondary" style="display: none;">
              👁️ Preview
            </button>
            <button id="js-save-template" class="js-button js-button-primary" style="display: none;">
              💾 Save
            </button>
          </div>
        </div>
        
        <!-- Editor / Preview Split - NUEVO -->
        <div class="js-editor-container">
          <div id="js-editor" class="js-editor"></div>
          
          <!-- Preview Frame - NUEVO (oculto por defecto en versión básica) -->
          <div id="js-preview-container" class="js-preview-container" style="display: none;">
            <div class="js-preview-header">
              <span>Preview</span>
              <button id="js-refresh-preview" class="js-button js-button-sm">🔄 Refresh</button>
            </div>
            <iframe id="js-preview-frame" class="js-preview-frame"></iframe>
            <div class="js-preview-upgrade">
              <p>💡 <strong>Juzt Studio Pro:</strong> Live preview while you edit</p>
            </div>
          </div>
        </div>
        
      </div>
      
    </div>
  `;

    app.innerHTML = structure;
    addCustomStyles();
  }

  function addCustomStyles() {
    if (document.getElementById("juzt-studio-styles")) return;

    const styles = document.createElement("style");
    styles.id = "juzt-studio-styles";
    styles.textContent = `
    /* Tabs del Sidebar */
    .js-sidebar-tabs {
      display: flex;
      border-bottom: 1px solid #ddd;
      background: #f7f7f7;
    }
    
    .js-tab-btn {
      flex: 1;
      padding: 12px 10px;
      background: none;
      border: none;
      cursor: pointer;
      font-size: 13px;
      font-weight: 500;
      color: #555;
      border-bottom: 2px solid transparent;
      transition: all 0.2s;
    }
    
    .js-tab-btn:hover {
      background: #fff;
      color: #23282d;
    }
    
    .js-tab-btn.active {
      color: #0073aa;
      border-bottom-color: #0073aa;
      background: #fff;
    }
    
    .js-tab-content {
      display: none;
    }
    
    .js-tab-content.active {
      display: block;
    }
    
    /* Editor Container con Preview */
    .js-editor-container {
      display: flex;
      height: 100%;
      overflow: hidden;
    }
    
    .js-editor {
      flex: 1;
      padding: 20px;
      overflow-y: auto;
    }
    
    .js-editor-container.split .js-editor {
      flex: 0 0 50%;
      border-right: 1px solid #ddd;
    }
    
    /* Preview Container */
    .js-preview-container {
      flex: 1;
      display: flex;
      flex-direction: column;
      background: #f9f9f9;
    }
    
    .js-preview-header {
      padding: 10px 15px;
      background: #fff;
      border-bottom: 1px solid #ddd;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 600;
    }
    
    .js-preview-frame {
      flex: 1;
      border: none;
      background: #fff;
    }
    
    .js-preview-upgrade {
      padding: 15px;
      background: #fff8e5;
      border-top: 1px solid #ffb900;
      text-align: center;
      font-size: 13px;
    }
    
    /* Settings Panel */
    .js-settings-field {
      margin-bottom: 20px;
    }
    
    /* Timber Status */
    .js-timber-status {
      padding: 8px 12px;
      border-radius: 4px;
      font-size: 12px;
      margin-top: 10px;
      text-align: center;
    }
    
    .js-timber-enabled {
      background: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    
    .js-timber-disabled {
      background: #fff3cd;
      color: #856404;
      border: 1px solid #ffeaa7;
    }
  `;

    document.head.appendChild(styles);
  }

  // ==========================================
  // OBTENER VALORES POR DEFECTO DE BLOQUE - NUEVO
  // ==========================================

  function getBlockDefaultSettings(sectionId, blockType) {
    const schema = state.sectionSchemas[sectionId]?.schema;

    if (!schema || !schema.blocks || !schema.blocks[blockType]) {
      console.log(
        `No schema found for block: ${blockType} in section: ${sectionId}`
      );
      return {};
    }

    const blockDefinition = schema.blocks[blockType];
    const properties = blockDefinition.properties || {};
    const defaultSettings = {};

    Object.entries(properties).forEach(([key, property]) => {
      // Aplicar valor por defecto si existe
      if (property.default !== undefined) {
        defaultSettings[key] = property.default;
      } else {
        // Si no hay default, usar valor apropiado según el tipo
        defaultSettings[key] = getDefaultValueByType(property.type);
      }
    });

    console.log(
      `Generated default settings for block ${blockType}:`,
      defaultSettings
    );

    return defaultSettings;
  }

  // ==========================================
  // AÑADIR BLOQUE - ACTUALIZADO con defaults
  // ==========================================

  function addBlock(sectionKey, blockType) {
    if (
      !state.selectedTemplate ||
      !state.selectedTemplate.sections ||
      !state.selectedTemplate.sections[sectionKey]
    ) {
      console.error(`Section ${sectionKey} does not exist`);
      return;
    }

    const section = state.selectedTemplate.sections[sectionKey];

    // Asegurar que blocks existe como array
    if (!section.blocks || !Array.isArray(section.blocks)) {
      section.blocks = [];
    }

    console.log(`Adding block type: ${blockType} to section: ${sectionKey}`);

    // NUEVO: Obtener valores por defecto del bloque
    const sectionType = section.section_id;
    const defaultBlockSettings = getBlockDefaultSettings(
      sectionType,
      blockType
    );

    console.log(
      `Default settings for block ${blockType}:`,
      defaultBlockSettings
    );

    // Crear nuevo bloque con settings por defecto
    const newBlock = {
      type: blockType,
      settings: defaultBlockSettings, // ← Settings con valores por defecto
    };

    section.blocks.push(newBlock);

    console.log(`Block added. Total blocks:`, section.blocks.length);
    console.log(`New block:`, newBlock);

    renderTemplateEditor();
    schedulePreviewRefresh();

    const blockDefinition =
      state.sectionSchemas[sectionType]?.schema?.blocks?.[blockType];
    const blockName = blockDefinition?.name || blockType;
    showMessage("success", `Block "${blockName}" added with default values`);
  }

  /**
   * Seleccionar archivo con WordPress Media Library
   */
  function selectWordPressFile(button) {
    if (!window.wp || !window.wp.media) {
      console.error("WordPress Media Library is not available");
      showMessage("error", "Error: WordPress Media Library not available");
      return;
    }

    const inputId = button.getAttribute("data-input");

    const frame = wp.media({
      title: "Select file",
      button: { text: "Use file" },
      multiple: false,
    });

    frame.on("select", function () {
      const attachment = frame.state().get("selection").first().toJSON();

      const input = document.getElementById(inputId);
      if (input) {
        input.value = attachment.url;

        const event = new Event("change", { bubbles: true });
        input.dispatchEvent(event);
      }
    });

    frame.open();
  }

  function registerEvents() {
    // ==========================================
    // EVENTOS DE BOTONES PRINCIPALES
    // ==========================================

    // Crear nueva plantilla
    const newTemplateBtn = document.getElementById("js-new-template");
    if (newTemplateBtn) {
      newTemplateBtn.addEventListener("click", createNewTemplate);
    }

    //close app
    if (dom.closeApp) {
      dom.closeApp.addEventListener("click", function () {
        window.location.href = window.sectionsBuilderData.adminUrl;
      });
    }

    // Clear Cache - NUEVO
    if (dom.clearCacheButton) {
      dom.clearCacheButton.addEventListener("click", clearCache);
    }

    // Guardar plantilla
    if (dom.saveButton) {
      dom.saveButton.addEventListener("click", saveTemplate);
    }

    // Toggle preview (versión básica = manual)
    if (dom.previewToggle) {
      dom.previewToggle.addEventListener("click", togglePreview);
    }

    // Refresh preview manual
    const refreshPreviewBtn = document.getElementById("js-refresh-preview");
    if (refreshPreviewBtn) {
      refreshPreviewBtn.addEventListener("click", refreshPreview);
    }

    // ==========================================
    // EVENTOS DE TABS DEL SIDEBAR
    // ==========================================

    const tabButtons = document.querySelectorAll(".js-tab-btn");
    tabButtons.forEach((button) => {
      button.addEventListener("click", function () {
        const tabName = this.getAttribute("data-tab");
        switchTab(tabName);
      });
    });

    // ==========================================
    // EVENTOS DE LISTAS (Delegation)
    // ==========================================

    // Click en plantillas
    if (dom.templatesList) {
      dom.templatesList.addEventListener("click", function (e) {
        const listItem = e.target.closest(".js-list-item");
        if (listItem) {
          const templateId = listItem.getAttribute("data-id");
          loadTemplate(templateId);
        }
      });
    }

    // Click en secciones
    if (dom.sectionsList) {
      dom.sectionsList.addEventListener("click", function (e) {
        const listItem = e.target.closest(".js-list-item");
        if (listItem) {
          const sectionId = listItem.getAttribute("data-id");
          addSection(sectionId);
        }
      });
    }

    // ==========================================
    // EVENTOS DEL EDITOR (Event Delegation)
    // ==========================================

    if (dom.editor) {
      // Toggle sección
      dom.editor.addEventListener("click", function (e) {
        const sectionHeader = e.target.closest(".js-section-header");
        if (sectionHeader && !e.target.closest(".js-section-actions")) {
          const section = sectionHeader.closest(".js-section");
          const sectionId = section.getAttribute("data-id");
          toggleSection(sectionId);
        }
      });

      // Remover sección
      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-remove-section")) {
          e.stopPropagation();
          const section = e.target.closest(".js-section");
          const sectionId = section.getAttribute("data-id");
          removeSection(sectionId);
        }
      });

      // Mover sección arriba
      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-move-up")) {
          e.stopPropagation();
          const section = e.target.closest(".js-section");
          const sectionId = section.getAttribute("data-id");
          moveSectionUp(sectionId);
        }
      });

      // Mover sección abajo
      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-move-down")) {
          e.stopPropagation();
          const section = e.target.closest(".js-section");
          const sectionId = section.getAttribute("data-id");
          moveSectionDown(sectionId);
        }
      });

      // ==========================================
      // EVENTOS DE BLOQUES
      // ==========================================

      // Agregar bloque
      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-add-block")) {
          e.preventDefault();
          const button = e.target.closest(".js-add-block");
          const sectionKey = button.getAttribute("data-section");
          const blockType = button.getAttribute("data-block-type");
          addBlock(sectionKey, blockType);
        }
      });

      // Remover bloque
      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-remove-block")) {
          e.stopPropagation();
          const block = e.target.closest(".js-block");
          const sectionKey = block.getAttribute("data-section");
          const blockIndex = parseInt(block.getAttribute("data-block-index"));
          removeBlock(sectionKey, blockIndex);
        }
      });

      // Mover bloque arriba
      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-move-block-up")) {
          e.stopPropagation();
          const block = e.target.closest(".js-block");
          const sectionKey = block.getAttribute("data-section");
          const blockIndex = parseInt(block.getAttribute("data-block-index"));
          moveBlockUp(sectionKey, blockIndex);
        }
      });

      // Mover bloque abajo
      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-move-block-down")) {
          e.stopPropagation();
          const block = e.target.closest(".js-block");
          const sectionKey = block.getAttribute("data-section");
          const blockIndex = parseInt(block.getAttribute("data-block-index"));
          moveBlockDown(sectionKey, blockIndex);
        }
      });

      // ==========================================
      // EVENTOS DE INPUTS (Change)
      // ==========================================

      // Cambios en settings de sección
      dom.editor.addEventListener("change", function (e) {
        if (e.target.classList.contains("js-setting-input")) {
          const section = e.target.closest(".js-section");
          const sectionKey = section.getAttribute("data-id");
          const setting = e.target.getAttribute("data-setting");
          const value = getInputValue(e.target);

          updateSectionSetting(sectionKey, setting, value);
          schedulePreviewRefresh(); // NUEVO: Actualizar preview (debounced)
        }
      });

      // Cambios en settings de bloque
      dom.editor.addEventListener("change", function (e) {
        if (e.target.classList.contains("js-block-setting-input")) {
          const section = e.target.closest(".js-section");
          const sectionKey = section.getAttribute("data-id");
          const blockIndex = parseInt(
            e.target.getAttribute("data-block-index")
          );
          const setting = e.target.getAttribute("data-setting");
          const value = getInputValue(e.target);

          updateBlockSetting(sectionKey, blockIndex, setting, value);
          schedulePreviewRefresh(); // NUEVO
        }
      });

      // Cambios en datos de plantilla
      dom.editor.addEventListener("change", function (e) {
        if (e.target.classList.contains("js-template-input")) {
          const field = e.target.getAttribute("data-field");
          const value = e.target.value;

          updateTemplateField(field, value);
        }
      });

      // Cambiar tipo de bloque
      dom.editor.addEventListener("change", function (e) {
        if (e.target.classList.contains("js-block-type-selector")) {
          const sectionKey = e.target.getAttribute("data-section");
          const blockIndex = parseInt(
            e.target.getAttribute("data-block-index")
          );
          const newBlockType = e.target.value;

          changeBlockType(sectionKey, blockIndex, newBlockType);
          schedulePreviewRefresh(); // NUEVO
        }
      });

      // ==========================================
      // EVENTOS DE IMÁGENES
      // ==========================================

      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-select-media")) {
          e.preventDefault();
          const button = e.target.closest(".js-select-media");
          selectWordPressMedia(button);
        }
      });

      // Remover imagen
      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-remove-image")) {
          e.preventDefault();
          const button = e.target.closest(".js-remove-image");
          removeSelectedImage(button);
        }
      });

      // ==========================================
      // EVENTOS DE CODE EDITOR EXPANDIBLE - NUEVO
      // ==========================================

      // Expandir editor de código
      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-expand-code")) {
          e.preventDefault();
          const button = e.target.closest(".js-expand-code");
          const textarea = button.previousElementSibling;
          openCodeModal(textarea);
        }
      });

      dom.editor.addEventListener("click", function (e) {
        if (e.target.closest(".js-select-file")) {
          e.preventDefault();
          const button = e.target.closest(".js-select-file");
          selectWordPressFile(button);
        }
      });
    }

    // ==========================================
    // EVENTOS DE SETTINGS GENERALES - NUEVO
    // ==========================================

    if (dom.settingsPanel) {
      // Cambios en settings generales
      dom.settingsPanel.addEventListener("change", function (e) {
        if (e.target.classList.contains("js-general-setting-input")) {
          const setting = e.target.getAttribute("data-setting");
          const value = getInputValue(e.target);

          updateGeneralSetting(setting, value);
          schedulePreviewRefresh();
        }
      });

      // Expandir code editor en settings generales
      dom.settingsPanel.addEventListener("click", function (e) {
        if (e.target.closest(".js-expand-code")) {
          e.preventDefault();
          const button = e.target.closest(".js-expand-code");
          const textarea = button.previousElementSibling;
          openCodeModal(textarea);
        }
      });

      // NUEVO: Seleccionar media (imagen/archivo) en settings generales
      dom.settingsPanel.addEventListener("click", function (e) {
        if (e.target.closest(".js-select-media")) {
          e.preventDefault();
          const button = e.target.closest(".js-select-media");
          selectWordPressMedia(button);
        }
      });

      // NUEVO: Remover media en settings generales
      dom.settingsPanel.addEventListener("click", function (e) {
        if (e.target.closest(".js-remove-image")) {
          e.preventDefault();
          const button = e.target.closest(".js-remove-image");
          removeSelectedImage(button);
        }
      });
    }
  }

  // Helper: Obtener valor del input según tipo
  function getInputValue(input) {
    const type = input.getAttribute("data-type");
    const settingKey = input.getAttribute("data-setting");

    // **CRÍTICO: Si el key es numérico, asegurarnos de manejarlo como string**
    if (!isNaN(settingKey) && settingKey !== "") {
      console.warn(
        `⚠️ Key numérico detectado: ${settingKey}, manejando como string`
      );
    }

    if (type === "number") {
      return parseFloat(input.value) || 0;
    } else if (type === "boolean") {
      return input.checked;
    } else if (type === "json") {
      try {
        return JSON.parse(input.value);
      } catch (e) {
        showMessage("error", "JSON inválido: " + e.message);
        return input.value;
      }
    } else {
      return input.value;
    }
  }

  // ==========================================
  // CARGAR PLANTILLAS
  // ==========================================

  async function loadTemplates() {
    try {
      // Intentar cargar templates agrupados
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_templates_by_source", // ← NUEVO endpoint
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success && data.data.templates_by_source) {
        // Guardar templates agrupados
        state.templatesBySource = data.data.templates_by_source;

        // También mantener lista plana para compatibilidad
        state.templates = {};
        Object.values(state.templatesBySource).forEach((source) => {
          source.templates.forEach((template) => {
            state.templates[template.id] = {
              ...template,
              source: source.id,
              source_name: source.name,
            };
          });
        });

        console.log("Templates por fuente:", state.templatesBySource);
        console.log("Templates planos:", state.templates);
        renderTemplatesList();
      } else {
        // Fallback: cargar con endpoint antiguo
        console.warn("Usando endpoint antiguo de templates");
        await loadTemplatesLegacy();
      }
    } catch (error) {
      console.error("Error loading templates:", error);
      await loadTemplatesLegacy();
    }
  }

  // Fallback: método anterior
  async function loadTemplatesLegacy() {
    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_templates",
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        state.templates = data.data;
        state.templatesBySource = null; // No hay agrupamiento
        console.log("Plantillas cargadas:", state.templates);
        renderTemplatesList();
      } else {
        throw new Error(data.data?.message || "Error loading templates");
      }
    } catch (error) {
      console.error("Error loading templates:", error);
      throw error;
    }
  }

  // ==========================================
  // CARGAR SECCIONES DISPONIBLES
  // ==========================================

  // Fallback: método anterior
  async function loadAvailableSectionsLegacy() {
    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_sections",
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        state.availableSections = data.data;
        state.sectionsBySource = null; // No hay agrupamiento
        console.log("Secciones Twig disponibles:", state.availableSections);
        renderSectionsList();
      } else {
        throw new Error("Error loading available sections");
      }
    } catch (error) {
      console.error("Error loading sections (legacy):", error);
      throw error;
    }
  }

  async function loadAvailableSections() {
    try {
      // Intentar cargar secciones agrupadas por fuente
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "juzt_get_sections_by_source", // ← NUEVO endpoint
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success && data.data.sections_by_source) {
        // Guardar secciones agrupadas
        state.sectionsBySource = data.data.sections_by_source;

        // También mantener lista plana para compatibilidad
        state.availableSections = {};
        Object.values(state.sectionsBySource).forEach((source) => {
          source.sections.forEach((section) => {
            state.availableSections[section.id] = {
              ...section,
              source: source.id,
              source_name: source.name,
            };
          });
        });

        console.log("Secciones por fuente:", state.sectionsBySource);
        console.log("Secciones planas:", state.availableSections);
        renderSectionsList();
      } else {
        // Fallback: cargar con endpoint antiguo
        console.warn("Usando endpoint antiguo de secciones");
        await loadAvailableSectionsLegacy();
      }
    } catch (error) {
      console.error("Error loading sections:", error);
      // Fallback
      await loadAvailableSectionsLegacy();
    }
  }

  // ==========================================
  // CARGAR SCHEMAS DE SECCIONES - ACTUALIZADO
  // ==========================================

  async function loadSectionSchemas() {
    try {
      // Intentar cargar schemas de todas las fuentes
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_all_section_schemas", // ← NUEVO endpoint
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        state.sectionSchemas = data.data;
        console.log(
          "Esquemas de secciones (todas las fuentes):",
          state.sectionSchemas
        );
        console.log("Schemas cargados:", Object.keys(state.sectionSchemas));
      } else {
        console.warn("Usando endpoint legacy de schemas");
        await loadSectionSchemasLegacy();
      }
    } catch (error) {
      console.error("Error loading schemas:", error);
      await loadSectionSchemasLegacy();
    }
  }

  // Fallback: método anterior (solo tema)
  async function loadSectionSchemasLegacy() {
    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_section_schemas",
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        state.sectionSchemas = data.data;
        console.log("Esquemas de secciones (legacy):", state.sectionSchemas);
      } else {
        throw new Error("Error loading section schemas");
      }
    } catch (error) {
      console.error("Error loading schemas (legacy):", error);
      throw error;
    }
  }
  // ==========================================
  // CARGAR SCHEMA DE SETTINGS GENERALES - NUEVO
  // ==========================================

  async function loadGeneralSettingsSchema() {
    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_general_settings_schema",
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        state.generalSettingsSchema = data.data;
        console.log(
          "Schema de settings generales:",
          state.generalSettingsSchema
        );
        renderGeneralSettingsPanel();
      } else {
        console.warn("No hay schema de settings generales disponible");
      }
    } catch (error) {
      console.error("Error loading settings schema:", error);
      // No lanzar error, ya que settings generales son opcionales
    }
  }

  // ==========================================
  // CARGAR SETTINGS GENERALES - NUEVO
  // ==========================================

  async function loadGeneralSettings() {
    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_general_settings",
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        state.generalSettings = data.data || {};
        console.log("Settings generales cargados:", state.generalSettings);
        renderGeneralSettingsPanel();
      } else {
        console.warn("No hay settings generales guardados");
        state.generalSettings = {};
      }
    } catch (error) {
      console.error("Error loading general settings:", error);
      state.generalSettings = {};
    }
  }

  async function loadPostTypes() {
    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_post_types",
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        state.availablePostTypes = data.data;
        console.log("Post types loaded:", state.availablePostTypes);
      }
    } catch (error) {
      console.error("Error loading post types:", error);
    }
  }

  // ==========================================
  // GUARDAR SETTINGS GENERALES - NUEVO
  // ==========================================

  async function saveGeneralSettings() {
    try {
      // Leer valores actuales del DOM
      const settingsData = {};
      const inputs = document.querySelectorAll(".js-general-setting-input");

      inputs.forEach((input) => {
        const key = input.getAttribute("data-setting");
        const type = input.getAttribute("data-type");

        if (type === "boolean") {
          settingsData[key] = input.checked;
        } else if (type === "number") {
          settingsData[key] = parseFloat(input.value) || 0;
        } else {
          settingsData[key] = input.value;
        }
      });

      console.log("=== GUARDANDO SETTINGS (del DOM) ===");
      console.log("Settings data:", settingsData);

      if (Object.keys(settingsData).length === 0) {
        showMessage("warning", "No settings to save");
        return;
      }

      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "save_general_settings",
          nonce: config.nonce,
          settings_data: JSON.stringify(settingsData), // ← Usar settingsData del DOM
        }),
      });

      const data = await response.json();

      if (data.success) {
        // Actualizar el state con los valores guardados
        state.generalSettings = settingsData;
        showMessage("success", "General settings saved successfully");
        schedulePreviewRefresh();
      } else {
        throw new Error(data.data?.message || "Error saving settings");
      }
    } catch (error) {
      console.error("Error guardando settings:", error);
      showMessage("error", "Error saving settings: " + error.message);
    }
  }

  // ==========================================
  // CARGAR PLANTILLA ESPECÍFICA
  // ==========================================

  async function loadTemplate(templateName) {
    setLoading(true);

    try {
      console.log("Cargando plantilla específica:", templateName);

      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_template",
          nonce: config.nonce,
          template_name: templateName,
        }),
      });

      const data = await response.json();

      if (data.success) {
        state.selectedTemplate = data.data;
        state.selectedTemplate._originalId = templateName;

        // Asegurar estructura compatible con Timber/Twig
        ensureTimberStructure();

        console.log("Plantilla cargada (Timber):", state.selectedTemplate);

        updateActiveTemplate();
        initExpandedSections();
        renderTemplateEditor();
        schedulePreviewRefresh(); // NUEVO
        setLoading(false);
      } else {
        throw new Error(data.data?.message || "Error loading the template");
      }
    } catch (error) {
      console.error("Error loading template:", error);
      showMessage("error", error.message);
      setLoading(false);
    }
  }

  // ==========================================
  // GUARDAR PLANTILLA
  // ==========================================

  // Limpiar caché
  async function clearCache() {
    if (
      !confirm(
        "¿Estás seguro de limpiar el caché? Se recargarán todas las plantillas y secciones."
      )
    ) {
      return;
    }

    const formData = new FormData();
    formData.append("action", "clear_builder_cache");
    formData.append("nonce", sectionsBuilderData.nonce);

    try {
      const response = await fetch(sectionsBuilderData.ajaxUrl, {
        method: "POST",
        body: formData,
      });

      const data = await response.json();

      if (data.success) {
        alert(
          "✅ " +
            data.data.message +
            "\n\nTemplates: " +
            data.data.templates_count +
            "\nSections: " +
            data.data.sections_count
        );
        location.reload(); // Recargar para ver cambios
      } else {
        alert("❌ Error: " + (data.data?.message || "Unknown error"));
      }
    } catch (error) {
      console.error("Error:", error);
      alert("❌ Error al limpiar caché");
    }
  }

  async function saveTemplate() {
    if (!state.selectedTemplate) {
      showMessage("error", "No template selected");
      return;
    }

    if (!state.selectedTemplate._originalId) {
      const templateName = prompt("Enter a unique name for the template:");
      if (!templateName) return;

      const validNameRegex = /^[a-zA-Z0-9_-]+$/;
      if (!validNameRegex.test(templateName)) {
        showMessage(
          "error",
          "Invalid name. Use letters, numbers, hyphens and underscores only."
        );
        return;
      }

      state.selectedTemplate._originalId = templateName;
    }

    setLoading(true);

    try {
      const templateName = state.selectedTemplate._originalId;

      // NUEVO: Capturar si se deben crear archivos
      const createFilesCheckbox = document.getElementById(
        "js-create-template-files"
      );
      const createFiles = createFilesCheckbox
        ? createFilesCheckbox.checked
        : true;

      console.log("=== SAVING TEMPLATE ===");
      console.log("Template name:", templateName);
      console.log("Create files:", createFiles);

      // CRÍTICO: Validar que sections es objeto antes de guardar
      if (Array.isArray(state.selectedTemplate.sections)) {
        console.error("❌ ERROR: sections is array, must be object");
        showMessage("error", "Internal error: sections is not a valid object");
        setLoading(false);
        return;
      }

      // Crear copia sin referencias internas
      const templateCopy = JSON.parse(JSON.stringify(state.selectedTemplate));
      delete templateCopy._originalId;
      delete templateCopy._createFiles;

      console.log("Structure to save:", templateCopy);
      console.log(
        "Type of sections:",
        Array.isArray(templateCopy.sections) ? "ARRAY ❌" : "OBJECT ✅"
      );
      console.log("Sections:", Object.keys(templateCopy.sections));

      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "save_template",
          nonce: config.nonce,
          template_name: templateName,
          template_data: JSON.stringify(templateCopy),
          create_files: createFiles ? "1" : "0", // NUEVO
        }),
      });

      const data = await response.json();

      if (data.success) {
        // Mensaje mejorado con info de archivos creados
        let message = `Template "${templateName}" saved successfully`;

        if (data.data && data.data.files_created) {
          message += "\n\nFiles created:";
          data.data.files_created.forEach((file) => {
            message += `\n✓ ${file}`;
          });
        }

        showMessage("success", message);
        state.selectedTemplate._originalId = templateName;
        state.selectedTemplate._createFiles = createFiles;
        await loadTemplates();
        setLoading(false);
      } else {
        throw new Error(data.data?.message || "Error saving template");
      }
    } catch (error) {
      console.error("Error saving:", error);
      showMessage("error", error.message);
      setLoading(false);
    }
  }

  // ==========================================
  // SWITCH TABS DEL SIDEBAR
  // ==========================================

  function switchTab(tabName) {
    // Actualizar botones
    const tabButtons = document.querySelectorAll(".js-tab-btn");
    tabButtons.forEach((btn) => {
      if (btn.getAttribute("data-tab") === tabName) {
        btn.classList.add("active");
      } else {
        btn.classList.remove("active");
      }
    });

    // Actualizar contenido
    const tabContents = document.querySelectorAll(".js-tab-content");
    tabContents.forEach((content) => {
      if (content.getAttribute("data-tab-content") === tabName) {
        content.classList.add("active");
      } else {
        content.classList.remove("active");
      }
    });
  }

  // ==========================================
  // MOSTRAR/OCULTAR LOADING
  // ==========================================

  function setLoading(isLoading) {
    if (isLoading) {
      if (dom.loading) dom.loading.style.display = "block";
      if (dom.builder) dom.builder.style.display = "none";
    } else {
      if (dom.loading) dom.loading.style.display = "none";
      if (dom.builder) dom.builder.style.display = "flex";
    }
  }

  // ==========================================
  // MOSTRAR MENSAJES
  // ==========================================

  function showMessage(type, text) {
    if (!dom.message) return;

    // Remover clases anteriores
    dom.message.className = "js-message";

    // Agregar clase del tipo
    dom.message.classList.add(`js-message-${type}`);
    dom.message.textContent = text;
    dom.message.style.display = "block";

    // Auto-ocultar después de 5 segundos
    setTimeout(() => {
      dom.message.style.display = "none";
    }, 5000);
  }

  // ==========================================
  // TOGGLE PREVIEW - NUEVO
  // ==========================================

  function togglePreview() {
    const previewContainer = document.getElementById("js-preview-container");
    const editorContainer = document.querySelector(".js-editor-container");

    if (!previewContainer || !editorContainer) return;

    state.previewMode = !state.previewMode;

    if (state.previewMode) {
      // Mostrar preview
      previewContainer.style.display = "flex";
      editorContainer.classList.add("split");
      dom.previewToggle.textContent = "📝 Editor";

      // Cargar preview
      refreshPreview();
    } else {
      // Ocultar preview
      previewContainer.style.display = "none";
      editorContainer.classList.remove("split");
      dom.previewToggle.textContent = "👁️ Preview";
    }
  }

  // ==========================================
  // REFRESH PREVIEW - NUEVO
  // ==========================================

  function refreshPreview() {
    if (!state.previewMode || !dom.previewFrame) return;

    // En versión básica, esto sería un reload manual del iframe
    // En versión Pro, sería en tiempo real

    if (!state.selectedTemplate) {
      dom.previewFrame.srcdoc =
        '<p style="padding: 20px; text-align: center;">Select a template to see the preview</p>';
      return;
    }

    // Generar URL de preview (esto dependerá de tu implementación en PHP)
    const previewUrl = `${config.ajaxUrl}?action=preview_template&nonce=${config.nonce}&template_id=${state.selectedTemplate._originalId}`;

    dom.previewFrame.src = previewUrl;

    showMessage("info", "Preview updated");
  }

  // ==========================================
  // SCHEDULE PREVIEW REFRESH (DEBOUNCED) - NUEVO
  // ==========================================

  function schedulePreviewRefresh() {
    // Solo si el preview está activo
    if (!state.previewMode) return;

    // Cancelar timeout anterior
    if (state.previewRefreshTimeout) {
      clearTimeout(state.previewRefreshTimeout);
    }

    // Programar nuevo refresh
    state.previewRefreshTimeout = setTimeout(() => {
      refreshPreview();
    }, config.previewDelay);
  }

  // ==========================================
  // RENDERIZAR LISTA DE PLANTILLAS
  // ==========================================

  function renderTemplatesList() {
    if (!dom.templatesList) return;

    dom.templatesList.innerHTML = "";

    // Si hay templates agrupados, usar ese formato
    if (state.templatesBySource) {
      renderTemplatesListGrouped();
    } else {
      // Fallback: lista simple
      renderTemplatesListSimple();
    }
  }

  function renderTemplatesListGrouped() {
    if (
      !state.templatesBySource ||
      Object.keys(state.templatesBySource).length === 0
    ) {
      dom.templatesList.innerHTML =
        '<div class="js-empty">No templates available</div>';
      return;
    }

    // Orden de renderizado: theme, extensiones, core
    const order = ["theme"];
    const extensionSources = Object.keys(state.templatesBySource).filter(
      (s) => s !== "theme" && s !== "core"
    );
    const finalOrder = [...order, ...extensionSources, "core"];

    let html = "";

    for (const sourceId of finalOrder) {
      if (!state.templatesBySource[sourceId]) {
        continue;
      }

      const source = state.templatesBySource[sourceId];
      const templates = source.templates || [];

      if (templates.length === 0) {
        continue;
      }

      // Determinar badge
      let badgeClass = "js-badge-extension";
      let badgeText = "EXT";

      if (sourceId === "theme") {
        badgeClass = "js-badge-theme";
        badgeText = "THEME";
      } else if (sourceId === "core") {
        badgeClass = "js-badge-core";
        badgeText = "CORE";
      }

      // Grupo de templates
      html += `
      <div class="js-templates-source">
        <div class="js-source-header">
          <span class="js-source-icon">📄</span>
          <span class="js-source-name">${escapeHtml(source.name)}</span>
          <span class="js-source-count">(${templates.length})</span>
        </div>
    `;

      // Templates del grupo
      templates.forEach((template) => {
        const isActive =
          state.selectedTemplate &&
          template.id === state.selectedTemplate._originalId;

        html += `
        <div class="js-list-item js-template-item ${
          isActive ? "active" : ""
        }" data-id="${template.id}">
          <div class="js-template-info">
            <div class="js-template-name">
              ${escapeHtml(template.name)}
              <span class="js-section-badge ${badgeClass}">${badgeText}</span>
            </div>
            <div class="js-template-meta">
              <span>${template.sections_count || 0} sections</span>
              ${
                template.post_type
                  ? `<span class="js-post-type-badge">${escapeHtml(
                      template.post_type
                    )}</span>`
                  : ""
              }
            </div>
            ${
              template.description
                ? `<div class="js-template-description">${escapeHtml(
                    template.description
                  )}</div>`
                : ""
            }
          </div>
        </div>
      `;
      });

      html += "</div>";
    }

    dom.templatesList.innerHTML = html;

    // Agregar estilos si no existen
    addTemplatesListStyles();
  }

  // Renderizar templates sin agrupamiento (fallback)
  function renderTemplatesListSimple() {
    if (!state.templates || Object.keys(state.templates).length === 0) {
      dom.templatesList.innerHTML =
        '<div class="js-empty">No templates available</div>';
      return;
    }

    Object.entries(state.templates).forEach(([id, template]) => {
      const name = template.name || id;
      const sectionsCount = template.sections_count || 0;

      const li = document.createElement("li");
      li.className = "js-list-item";
      li.setAttribute("data-id", id);

      if (state.selectedTemplate && id === state.selectedTemplate._originalId) {
        li.classList.add("active");
      }

      li.innerHTML = `
      <div class="js-template-info">
        <div class="js-template-name">${escapeHtml(name)}</div>
        <div class="js-template-meta">${sectionsCount} sections</div>
      </div>
    `;

      dom.templatesList.appendChild(li);
    });
  }

  // Agregar estilos para templates agrupados
  function addTemplatesListStyles() {
    if (document.getElementById("juzt-templates-grouped-styles")) return;

    const styles = document.createElement("style");
    styles.id = "juzt-templates-grouped-styles";
    styles.textContent = `
    .js-templates-source {
      margin-bottom: 20px;
    }
    
    .js-template-item {
      margin-left: 8px;
      margin-bottom: 6px;
      padding: 10px 12px;
      border-left: 2px solid transparent;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .js-template-item:hover {
      border-left-color: #0073aa;
      background: #f9f9f9;
    }
    
    .js-template-item.active {
      background: #e5f5fa;
      border-left-color: #0073aa;
    }
    
    .js-template-name {
      font-weight: 600;
      font-size: 13px;
      margin-bottom: 4px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    
    .js-template-meta {
      font-size: 11px;
      color: #666;
      display: flex;
      gap: 8px;
      align-items: center;
    }
    
    .js-post-type-badge {
      background: #f0f0f1;
      padding: 2px 6px;
      border-radius: 3px;
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
    }
    
    .js-template-description {
      font-size: 11px;
      color: #666;
      margin-top: 4px;
      line-height: 1.3;
    }
  `;

    document.head.appendChild(styles);
  }

  // ==========================================
  // RENDERIZAR LISTA DE SECCIONES
  // ==========================================

  function renderSectionsList() {
    if (!dom.sectionsList) return;

    dom.sectionsList.innerHTML = "";

    // Si hay secciones agrupadas, usar ese formato
    if (state.sectionsBySource) {
      renderSectionsListGrouped();
    } else {
      // Fallback: lista simple
      renderSectionsListSimple();
    }
  }

  // Renderizar secciones agrupadas por fuente
  function renderSectionsListGrouped() {
    if (
      !state.sectionsBySource ||
      Object.keys(state.sectionsBySource).length === 0
    ) {
      dom.sectionsList.innerHTML =
        '<div class="js-empty">No Twig sections available</div>';
      return;
    }

    // Orden de renderizado: theme, extensiones, core
    const order = ["theme"];
    const extensionSources = Object.keys(state.sectionsBySource).filter(
      (s) => s !== "theme" && s !== "core"
    );
    const finalOrder = [...order, ...extensionSources, "core"];

    let html = "";

    for (const sourceId of finalOrder) {
      if (!state.sectionsBySource[sourceId]) {
        continue;
      }

      const source = state.sectionsBySource[sourceId];
      const sections = source.sections || [];

      if (sections.length === 0) {
        continue;
      }

      // Determinar badge
      let badgeClass = "js-badge-extension";
      let badgeText = "EXT";

      if (sourceId === "theme") {
        badgeClass = "js-badge-theme";
        badgeText = "THEME";
      } else if (sourceId === "core") {
        badgeClass = "js-badge-core";
        badgeText = "CORE";
      }

      // Grupo de secciones
      html += `
      <div class="js-sections-source">
        <div class="js-source-header">
          <span class="js-source-icon">📦</span>
          <span class="js-source-name">${escapeHtml(source.name)}</span>
          <span class="js-source-count">(${sections.length})</span>
        </div>
    `;

      // Secciones del grupo
      sections.forEach((section) => {
        const hasSchema = state.sectionSchemas[section.id] ? true : false;
        const schemaIndicator = hasSchema ? "⚙️" : "📄";

        html += `
        <div class="js-list-item js-section-item" data-id="${
          section.id
        }" title="${escapeHtml(
          section.description || "Section " + section.name
        )}">
          <div class="js-section-info">
            <div class="js-section-name">
              <span class="js-section-indicator">${schemaIndicator}</span>
              ${escapeHtml(section.name)}
              <span class="js-section-badge ${badgeClass}">${badgeText}</span>
            </div>
            ${
              section.description
                ? `<div class="js-section-description">${escapeHtml(
                    section.description
                  )}</div>`
                : ""
            }
          </div>
        </div>
      `;
      });

      html += "</div>";
    }

    dom.sectionsList.innerHTML = html;

    // Agregar estilos si no existen
    addSectionsListStyles();
  }

  function addSectionsListStyles() {
    if (document.getElementById("juzt-sections-grouped-styles")) return;

    const styles = document.createElement("style");
    styles.id = "juzt-sections-grouped-styles";
    styles.textContent = `
    .js-sections-source {
      margin-bottom: 20px;
    }
    
    .js-source-header {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 12px;
      background: #f7f7f7;
      border-left: 3px solid #0073aa;
      font-weight: 600;
      font-size: 13px;
      margin-bottom: 8px;
      border-radius: 3px;
    }
    
    .js-source-icon {
      font-size: 16px;
    }
    
    .js-source-name {
      flex: 1;
    }
    
    .js-source-count {
      font-size: 11px;
      color: #666;
      font-weight: 400;
    }
    
    .js-section-item {
      margin-left: 8px;
      margin-bottom: 6px;
      padding: 10px 12px;
      border-left: 2px solid transparent;
    }
    
    .js-section-item:hover {
      border-left-color: #0073aa;
      background: #f9f9f9;
    }
    
    .js-section-badge {
      font-size: 9px;
      font-weight: 700;
      padding: 2px 6px;
      border-radius: 3px;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      margin-left: 6px;
    }
    
    .js-badge-theme {
      background: #00a32a;
      color: #fff;
    }
    
    .js-badge-core {
      background: #646970;
      color: #fff;
    }
    
    .js-badge-extension {
      background: #9b51e0;
      color: #fff;
    }
    
    .js-section-description {
      font-size: 11px;
      color: #666;
      margin-top: 4px;
      line-height: 1.3;
    }
  `;

    document.head.appendChild(styles);
  }

  // Renderizar secciones sin agrupamiento (fallback)
  function renderSectionsListSimple() {
    if (
      !state.availableSections ||
      Object.keys(state.availableSections).length === 0
    ) {
      dom.sectionsList.innerHTML =
        '<div class="js-empty">No Twig sections available</div>';
      return;
    }

    Object.entries(state.availableSections).forEach(([id, section]) => {
      const name = section.name || id;
      const hasSchema = section.has_schema;
      const schemaIndicator = hasSchema ? "⚙️" : "📄";
      const templateFile = section.template_file || id + ".twig";

      const li = document.createElement("li");
      li.className = "js-list-item";
      li.setAttribute("data-id", id);
      li.setAttribute("title", section.description || "Section " + name);

      li.innerHTML = `
      <div class="js-section-info">
        <div class="js-section-name">
          <span class="js-section-indicator">${schemaIndicator}</span>
          ${escapeHtml(name)}
        </div>
        <div class="js-section-meta">
          <span class="js-template-path">${escapeHtml(templateFile)}</span>
          <span class="js-section-schema-info">
            ${hasSchema ? "with schema" : "no schema"}
          </span>
        </div>
      </div>
    `;

      dom.sectionsList.appendChild(li);
    });
  }

  // ==========================================
  // RENDERIZAR PANEL DE SETTINGS GENERALES - NUEVO
  // ==========================================

  function renderGeneralSettingsPanel() {
    if (!dom.settingsPanel) return;

    // Si no hay schema, mostrar mensaje
    if (
      !state.generalSettingsSchema ||
      !state.generalSettingsSchema.properties
    ) {
      dom.settingsPanel.innerHTML = `
      <div class="js-empty">
        <p>No general settings schema defined.</p>
        <p>Create a file <code>config/settings_schema.php</code> in your theme.</p>
      </div>
    `;
      return;
    }

    let html = '<div class="js-settings-fields">';

    const schema = state.generalSettingsSchema;
    const properties = schema.properties || {};

    // Renderizar cada campo del schema
    Object.entries(properties).forEach(([key, property]) => {
      const value =
        state.generalSettings[key] !== undefined
          ? state.generalSettings[key]
          : property.default || "";

      html += renderGeneralSettingField(key, property, value);
    });

    html += "</div>";

    // Botón de guardar
    html += `
    <div class="js-settings-actions" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
      <button id="js-save-general-settings" class="js-button js-button-primary">
        💾 Save Settings
      </button>
    </div>
  `;

    dom.settingsPanel.innerHTML = html;

    // Registrar evento del botón guardar
    const saveBtn = document.getElementById("js-save-general-settings");
    if (saveBtn) {
      saveBtn.addEventListener("click", saveGeneralSettings);
    }
  }

  // ==========================================
  // RENDERIZAR CAMPO DE SETTING GENERAL - ACTUALIZADO CON TODOS LOS TIPOS
  // ==========================================

  function renderGeneralSettingField(fieldKey, property, value) {
    const type = property.type || "string";
    const title = property.title || fieldKey;
    const description = property.description || "";
    const fieldId = `general-setting-${fieldKey}`;

    let fieldHtml = "";

    // ==========================================
    // CAMPO CODE con modal expandible
    // ==========================================
    if (type === "code" || property.format === "code") {
      const language = property.language || "text";
      const expandable = property.expandable !== false;

      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <textarea 
          id="${fieldId}"
          class="js-form-textarea js-general-setting-input" 
          data-setting="${fieldKey}"
          data-type="string"
          data-language="${language}"
          rows="8"
        >${escapeHtml(value)}</textarea>
        ${
          expandable
            ? `
          <button class="js-button js-button-secondary js-expand-code" style="margin-top: 5px;">
            ⛶ Expandir Editor
          </button>
        `
            : ""
        }
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // HTML / RICHTEXT (Code Editor)
    // ==========================================
    else if (
      type === "html" ||
      type === "richtext" ||
      property.format === "html" ||
      property.format === "richtext"
    ) {
      const language = type === "html" ? "html" : "text";
      const expandable = property.expandable !== false;

      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <textarea 
          id="${fieldId}"
          class="js-form-textarea js-general-setting-input" 
          data-setting="${fieldKey}"
          data-type="string"
          data-language="${language}"
          rows="8"
        >${escapeHtml(value)}</textarea>
        ${
          expandable
            ? `
          <button class="js-button js-button-secondary js-expand-code" style="margin-top: 5px;">
            ⛶ Expandir Editor
          </button>
        `
            : ""
        }
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // IMAGE O FILE (UNIFICADO)
    // ==========================================
    else if (property.format === "image" || property.format === "file") {
      const hasMedia = value !== "";
      const previewId = `preview-${fieldId}`;
      const mediaType = property.format === "image" ? "image" : "all";

      fieldHtml = `
    <div class="js-form-group js-settings-field">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <div class="js-image-field">
        <input 
          type="text" 
          id="${fieldId}"
          class="js-form-input js-general-setting-input" 
          value="${escapeHtml(value)}"
          data-setting="${fieldKey}"
          data-type="string"
          placeholder="File ID"
          readonly
        />
        <button 
          class="js-button js-button-secondary js-select-media" 
          data-input="${fieldId}" 
          data-preview="${previewId}"
          data-media-type="${mediaType}"
          type="button">
          ${mediaType === "image" ? "📷 Select" : "📎 Select"}
        </button>
      </div>
      <div id="${previewId}" class="js-image-preview" style="${
        hasMedia ? "" : "display: none;"
      }">
        ${
          hasMedia
            ? `<div class="js-media-preview-loading">Cargando preview...</div>`
            : ""
        }
        <button 
          class="js-button js-button-danger js-remove-image" 
          data-input="${fieldId}" 
          data-preview="${previewId}"
          type="button">×</button>
      </div>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;

      // Si ya hay un valor (ID), cargar preview
      if (hasMedia && value) {
        setTimeout(() => {
          loadAttachmentPreview(previewId, value);
        }, 100);
      }
    }

    // ==========================================
    // VIDEO URL
    // ==========================================
    else if (type === "video_url" || property.format === "video_url") {
      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <input 
          class="js-form-input js-general-setting-input" 
          type="url" 
          value="${escapeHtml(value)}"
          data-setting="${fieldKey}"
          data-type="string"
          placeholder="https://youtube.com/watch?v=..."
        />
        <small class="js-form-help">📹 Acepta YouTube, Vimeo, etc.</small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // SHORTCODE
    // ==========================================
    else if (type === "shortcode" || property.format === "shortcode") {
      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <input 
          class="js-form-input js-general-setting-input" 
          type="text" 
          value="${escapeHtml(value)}"
          data-setting="${fieldKey}"
          data-type="string"
          placeholder="[shortcode attr='value']"
          style="font-family: monospace;"
        />
        <small class="js-form-help">📌 Se ejecutará con do_shortcode() en Twig</small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // POST / PAGE / PRODUCT (WordPress Post Types)
    // ==========================================
    else if (["post", "page", "product", "collection", "blog"].includes(type)) {
      const postTypeMap = {
        post: "post",
        page: "page",
        product: "product",
        collection: "product_cat",
        blog: "post",
      };

      const postType = property.post_type || postTypeMap[type] || "post";
      const typeLabel = type.charAt(0).toUpperCase() + type.slice(1);

      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <select 
          id="${fieldId}"
          class="js-form-select js-general-setting-input js-post-select" 
          data-setting="${fieldKey}" 
          data-type="string"
          data-post-type="${postType}"
        >
          <option value="">-- Select ${typeLabel} --</option>
        </select>
        <small class="js-form-help">🔄 Loading ${typeLabel}s...</small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;

      // Load posts after rendering
      setTimeout(() => {
        loadPostsForSelect(fieldId, postType, value);
      }, 100);
    }

    // ==========================================
    // TAXONOMY (Categorías, Tags, etc)
    // ==========================================
    else if (type === "taxonomy" || property.format === "taxonomy") {
      const taxonomy = property.taxonomy || "category";
      const taxonomyLabel =
        taxonomy.charAt(0).toUpperCase() + taxonomy.slice(1);

      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <select 
          id="${fieldId}"
          class="js-form-select js-general-setting-input js-taxonomy-select" 
          data-setting="${fieldKey}" 
          data-type="string"
          data-taxonomy="${taxonomy}"
        >
          <option value="">-- Select ${taxonomyLabel} --</option>
        </select>
        <small class="js-form-help">🔄 Loading terms...</small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;

      // Load terms after rendering
      setTimeout(() => {
        loadTermsForSelect(fieldId, taxonomy, value);
      }, 100);
    }

    // ==========================================
    // MENU (WordPress Nav Menus)
    // ==========================================
    else if (type === "menu" || property.format === "menu") {
      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <select 
          id="${fieldId}"
          class="js-form-select js-general-setting-input js-menu-select" 
          data-setting="${fieldKey}" 
          data-type="string"
        >
          <option value="">-- Select Menu --</option>
        </select>
        <small class="js-form-help">🔄 Loading menus...</small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;

      // Load menus after rendering
      setTimeout(() => {
        loadMenusForSelect(fieldId, value);
      }, 100);
    }

    // ==========================================
    // WIDGET / SIDEBAR
    // ==========================================
    else if (type === "widget" || property.format === "widget") {
      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <select 
          id="${fieldId}"
          class="js-form-select js-general-setting-input js-sidebar-select" 
          data-setting="${fieldKey}" 
          data-type="string"
        >
          <option value="">-- Select Sidebar --</option>
        </select>
        <small class="js-form-help">🔄 Loading sidebars...</small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;

      // Load sidebars after rendering
      setTimeout(() => {
        loadSidebarsForSelect(fieldId, value);
      }, 100);
    }

    // ==========================================
    // SELECT con opciones (enum)
    // ==========================================
    else if (property.enum) {
      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <select 
          class="js-form-select js-general-setting-input" 
          data-setting="${fieldKey}" 
          data-type="string"
        >
          ${property.enum
            .map((option, idx) => {
              const label = property.enumNames
                ? property.enumNames[idx]
                : option;
              return `<option value="${escapeHtml(option)}" ${
                value === option ? "selected" : ""
              }>${escapeHtml(label)}</option>`;
            })
            .join("")}
        </select>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // BOOLEAN (checkbox)
    // ==========================================
    else if (type === "boolean") {
      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">
          <input 
            type="checkbox" 
            class="js-general-setting-input"
            ${value ? "checked" : ""}
            data-setting="${fieldKey}"
            data-type="boolean"
          />
          ${escapeHtml(title)}
        </label>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // NUMBER
    // ==========================================
    else if (type === "number") {
      const min =
        property.minimum !== undefined ? `min="${property.minimum}"` : "";
      const max =
        property.maximum !== undefined ? `max="${property.maximum}"` : "";
      const step =
        property.multipleOf !== undefined
          ? `step="${property.multipleOf}"`
          : "";

      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <input 
          class="js-form-input js-general-setting-input" 
          type="number" 
          value="${value}"
          data-setting="${fieldKey}"
          data-type="number"
          ${min} ${max} ${step}
        />
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // COLOR
    // ==========================================
    else if (type === "color" || property.format === "color") {
      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <input 
          class="js-form-input js-general-setting-input" 
          type="color" 
          value="${value}"
          data-setting="${fieldKey}"
          data-type="string"
        />
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // TEXTAREA
    // ==========================================
    else if (property.format === "textarea") {
      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <textarea 
          class="js-form-textarea js-general-setting-input" 
          data-setting="${fieldKey}"
          data-type="string"
          rows="4"
        >${escapeHtml(value)}</textarea>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // TEXT INPUT (por defecto)
    // ==========================================
    else {
      fieldHtml = `
      <div class="js-form-group js-settings-field">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <input 
          class="js-form-input js-general-setting-input" 
          type="text" 
          value="${escapeHtml(value)}"
          data-setting="${fieldKey}"
          data-type="string"
        />
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    return fieldHtml;
  }

  // ==========================================
  // ACTUALIZAR SETTING GENERAL - NUEVO
  // ==========================================

  function updateGeneralSetting(key, value) {
    state.generalSettings[key] = value;
    console.log(`Setting general actualizado: ${key} =`, value);
  }

  // ==========================================
  // ABRIR MODAL DE CODE EDITOR - NUEVO
  // ==========================================

  function openCodeModal(textarea) {
    if (!textarea) return;

    const currentValue = textarea.value;
    const language = textarea.getAttribute("data-language") || "text";

    // Crear modal
    const modal = document.createElement("div");
    modal.className = "js-code-modal";
    modal.innerHTML = `
    <div class="js-code-modal-overlay"></div>
    <div class="js-code-modal-content">
      <div class="js-code-modal-header">
        <h3>Editor de Código - ${language.toUpperCase()}</h3>
        <button class="js-code-modal-close js-button js-button-secondary">✕ Cerrar</button>
      </div>
      <div class="js-code-modal-body">
        <textarea 
          class="js-code-modal-textarea" 
          rows="25"
        >${escapeHtml(currentValue)}</textarea>
      </div>
      <div class="js-code-modal-footer">
        <p class="js-help-text">
          💡 <strong>Juzt Studio Pro:</strong> Editor Monaco con syntax highlighting, autocomplete y linting en tiempo real
        </p>
        <button class="js-code-modal-save js-button js-button-primary">Aplicar Cambios</button>
      </div>
    </div>
  `;

    // Agregar estilos del modal si no existen
    addCodeModalStyles();

    // Agregar al DOM
    document.body.appendChild(modal);

    // Referencias
    const modalTextarea = modal.querySelector(".js-code-modal-textarea");
    const closeBtn = modal.querySelector(".js-code-modal-close");
    const saveBtn = modal.querySelector(".js-code-modal-save");
    const overlay = modal.querySelector(".js-code-modal-overlay");

    // Focus en el textarea
    setTimeout(() => {
      modalTextarea.focus();
    }, 100);

    // Función para cerrar
    function closeModal() {
      modal.remove();
    }

    // Función para guardar
    function saveChanges() {
      textarea.value = modalTextarea.value;

      // Trigger change event
      const event = new Event("change", { bubbles: true });
      textarea.dispatchEvent(event);

      closeModal();
      showMessage("success", "Código actualizado");
    }

    // Eventos
    closeBtn.addEventListener("click", closeModal);
    overlay.addEventListener("click", closeModal);
    saveBtn.addEventListener("click", saveChanges);

    // ESC para cerrar
    function handleEsc(e) {
      if (e.key === "Escape") {
        closeModal();
        document.removeEventListener("keydown", handleEsc);
      }
    }
    document.addEventListener("keydown", handleEsc);
  }

  // ==========================================
  // ESTILOS DEL MODAL DE CÓDIGO - NUEVO
  // ==========================================

  function addCodeModalStyles() {
    if (document.getElementById("js-code-modal-styles")) return;

    const styles = document.createElement("style");
    styles.id = "js-code-modal-styles";
    styles.textContent = `
    .js-code-modal {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 100000;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .js-code-modal-overlay {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
    }
    
    .js-code-modal-content {
      position: relative;
      background: #fff;
      border-radius: 6px;
      width: 90%;
      max-width: 1000px;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    }
    
    .js-code-modal-header {
      padding: 15px 20px;
      border-bottom: 1px solid #ddd;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #f7f7f7;
      border-radius: 6px 6px 0 0;
    }
    
    .js-code-modal-header h3 {
      margin: 0;
      font-size: 16px;
    }
    
    .js-code-modal-body {
      padding: 20px;
      flex: 1;
      overflow: auto;
    }
    
    .js-code-modal-textarea {
      width: 100%;
      font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
      font-size: 13px;
      line-height: 1.5;
      border: 1px solid #ddd;
      border-radius: 4px;
      padding: 12px;
      resize: vertical;
    }
    
    .js-code-modal-textarea:focus {
      outline: none;
      border-color: #0073aa;
      box-shadow: 0 0 0 1px #0073aa;
    }
    
    .js-code-modal-footer {
      padding: 15px 20px;
      border-top: 1px solid #ddd;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #f9f9f9;
      border-radius: 0 0 6px 6px;
    }
    
    .js-code-modal-footer .js-help-text {
      margin: 0;
      font-size: 12px;
      color: #666;
    }
  `;

    document.head.appendChild(styles);
  }

  // ==========================================
  // CREAR NUEVA PLANTILLA
  // ==========================================

  function createNewTemplate() {
    const templateName = prompt("Enter a name for the new template:");
    if (!templateName) return;

    const validNameRegex = /^[a-zA-Z0-9_-]+$/;
    if (!validNameRegex.test(templateName)) {
      showMessage(
        "error",
        "Invalid name. Use letters, numbers, hyphens and underscores only."
      );
      return;
    }

    // Crear estructura compatible con Timber/Twig
    // CRÍTICO: sections debe ser objeto, no array
    state.selectedTemplate = {
      name: templateName,
      description: "Template created with Juzt Studio for Timber/Twig",
      template: true,
      order: [], // Array
      sections: {}, // ← OBJETO, no array
      _originalId: templateName,
    };

    console.log("New Timber template created:", state.selectedTemplate);
    console.log(
      "Type of sections:",
      Array.isArray(state.selectedTemplate.sections) ? "ARRAY ❌" : "OBJECT ✅"
    );

    updateActiveTemplate();
    initExpandedSections();
    renderTemplateEditor();

    showMessage(
      "success",
      `Nueva plantilla "${templateName}" creada. No olvides guardarla.`
    );
  }

  // ==========================================
  // ASEGURAR ESTRUCTURA TIMBER
  // ==========================================

  function ensureTimberStructure() {
    if (!state.selectedTemplate) return;

    // Asegurar que existe order como ARRAY
    if (
      !state.selectedTemplate.order ||
      !Array.isArray(state.selectedTemplate.order)
    ) {
      state.selectedTemplate.order = Object.keys(
        state.selectedTemplate.sections || {}
      );
    }

    // CRÍTICO: Asegurar que sections es OBJETO, nunca array
    if (
      !state.selectedTemplate.sections ||
      typeof state.selectedTemplate.sections !== "object" ||
      Array.isArray(state.selectedTemplate.sections) // ← VALIDACIÓN EXTRA
    ) {
      console.warn("⚠️ sections was not an object, fixing...");
      state.selectedTemplate.sections = {};
    }

    // Asegurar que cada sección tiene estructura consistente
    Object.keys(state.selectedTemplate.sections).forEach((sectionKey) => {
      const section = state.selectedTemplate.sections[sectionKey];

      if (!section.section_id) {
        section.section_id = sectionKey.replace(/^section_\d+_/, "");
      }

      // CRÍTICO: Forzar settings como objeto, nunca como array
      if (!section.settings || Array.isArray(section.settings)) {
        console.warn(
          `⚠️ Section ${sectionKey} had settings as array, converting to object`
        );
        section.settings = {};
      }

      // Validar que settings es objeto asociativo
      if (section.settings && Array.isArray(section.settings)) {
        console.error(
          `❌ ERROR CRÍTICO: section.settings es array en ${sectionKey}`
        );
        section.settings = {};
      }

      // Lo mismo para blocks (debe ser array)
      if (!Array.isArray(section.blocks)) {
        section.blocks = [];
      }

      // Validar settings de cada bloque
      if (section.blocks && Array.isArray(section.blocks)) {
        section.blocks.forEach((block) => {
          if (!block.settings || Array.isArray(block.settings)) {
            block.settings = {};
          }
        });
      }
    });

    console.log("✅ Timber structure ensured:", state.selectedTemplate);
    console.log(
      "Type of sections:",
      Array.isArray(state.selectedTemplate.sections) ? "ARRAY ❌" : "OBJECT ✅"
    );
    console.log(
      "Number of sections:",
      Object.keys(state.selectedTemplate.sections).length
    );

    // Debug: verificar estructura de settings
    Object.keys(state.selectedTemplate.sections).forEach((sectionKey) => {
      const section = state.selectedTemplate.sections[sectionKey];
      console.log(`Section ${sectionKey}:`, {
        hasSettings: !!section.settings,
        isSettingsArray: Array.isArray(section.settings),
        settingsKeys: section.settings ? Object.keys(section.settings) : [],
      });
    });

    // NUEVO: Aplicar defaults a secciones existentes que no los tienen
    Object.keys(state.selectedTemplate.sections).forEach((sectionKey) => {
      const section = state.selectedTemplate.sections[sectionKey];
      const sectionId = section.section_id;

      // Obtener schema
      const schema = state.sectionSchemas[sectionId]?.schema;
      if (schema && schema.properties) {
        const properties = schema.properties;

        // Aplicar defaults a settings que no existen
        Object.entries(properties).forEach(([key, property]) => {
          if (
            section.settings[key] === undefined &&
            property.default !== undefined
          ) {
            section.settings[key] = property.default;
            console.log(
              `Applied default for ${sectionKey}.settings.${key}:`,
              property.default
            );
          }
        });
      }

      // Lo mismo para bloques
      if (section.blocks && Array.isArray(section.blocks)) {
        section.blocks.forEach((block, blockIndex) => {
          const blockType = block.type;
          const blockSchema = schema?.blocks?.[blockType];

          if (blockSchema && blockSchema.properties) {
            Object.entries(blockSchema.properties).forEach(
              ([key, property]) => {
                if (
                  block.settings[key] === undefined &&
                  property.default !== undefined
                ) {
                  block.settings[key] = property.default;
                  console.log(
                    `Applied default for block ${blockIndex}.settings.${key}:`,
                    property.default
                  );
                }
              }
            );
          }
        });
      }
    });

    console.log(
      "✅ Timber structure ensured with defaults:",
      state.selectedTemplate
    );
  }

  // ==========================================
  // ACTUALIZAR TEMPLATE ACTIVO
  // ==========================================

  function updateActiveTemplate() {
    if (dom.templateTitle) {
      const title = state.selectedTemplate
        ? state.selectedTemplate.name || "Untitled"
        : "Juzt Studio - Template Builder";
      dom.templateTitle.textContent = title;
    }

    if (dom.saveButton) {
      dom.saveButton.style.display = state.selectedTemplate
        ? "inline-flex"
        : "none";
    }

    if (dom.previewToggle) {
      dom.previewToggle.style.display = state.selectedTemplate
        ? "inline-flex"
        : "none";
    }

    // Actualizar lista de plantillas
    if (dom.templatesList) {
      const items = dom.templatesList.querySelectorAll(".js-list-item");
      items.forEach((item) => {
        item.classList.remove("active");
        if (
          state.selectedTemplate &&
          item.getAttribute("data-id") === state.selectedTemplate._originalId
        ) {
          item.classList.add("active");
        }
      });
    }
  }

  // ==========================================
  // INICIALIZAR SECCIONES EXPANDIDAS
  // ==========================================

  function initExpandedSections() {
    state.expandedSections = {};

    if (!state.selectedTemplate || !state.selectedTemplate.order) return;

    state.selectedTemplate.order.forEach((sectionKey) => {
      state.expandedSections[sectionKey] = false;
    });

    // Expandir la primera sección por defecto
    if (state.selectedTemplate.order.length > 0) {
      state.expandedSections[state.selectedTemplate.order[0]] = true;
    }
  }

  // ==========================================
  // RENDERIZAR EDITOR DE PLANTILLA
  // ==========================================

  function renderTemplateEditor() {
    if (!dom.editor) return;

    dom.editor.innerHTML = "";

    if (!state.selectedTemplate) {
      dom.editor.innerHTML =
        '<div class="js-empty">Select a template to edit or create a new one</div>';
      return;
    }

    const propertiesPanel = `
    <div class="js-panel">
      <h3 class="js-panel-title">Template properties</h3>
      <div class="js-panel-content">
        <div class="js-form-group">
          <label class="js-form-label">Name</label>
          <input 
            class="js-form-input js-template-input" 
            type="text" 
            value="${escapeHtml(state.selectedTemplate.name || "")}"
            data-field="name"
          />
        </div>
        
        <div class="js-form-group">
          <label class="js-form-label">Description</label>
          <textarea 
            class="js-form-textarea js-template-input"
            data-field="description"
            rows="3"
          >${escapeHtml(state.selectedTemplate.description || "")}</textarea>
        </div>

        <div class="js-form-group">
          <label class="js-form-label">Template Post Type</label>
          <select 
            class="js-form-select js-template-input" 
            data-field="post_type"
          >
            <option value="">-- Select Post Type --</option>
            ${state.availablePostTypes
              .map(
                (pt) =>
                  `<option value="${escapeHtml(pt.name)}" ${
                    state.selectedTemplate.post_type === pt.name
                      ? "selected"
                      : ""
                  }>${escapeHtml(pt.label)}</option>`
              )
              .join("")}
          </select>
          <p class="js-form-help">
            Define which post type this template applies to. Leave empty for general templates.
          </p>
        </div>
        
        <div class="js-form-group">
          <label class="js-form-label">
            <input 
              type="checkbox" 
              id="js-create-template-files"
              ${state.selectedTemplate._createFiles !== false ? "checked" : ""}
            />
            Create PHP and Twig files automatically
          </label>
          <p class="js-form-help">
            Generates <code>${escapeHtml(
              state.selectedTemplate._originalId || "template-name"
            )}.php</code> and 
            <code>views/templates/${escapeHtml(
              state.selectedTemplate._originalId || "template-name"
            )}.twig</code>
          </p>
        </div>
      </div>
    </div>
  `;

    const sectionsPanel = `
    <div class="js-panel">
      <h3 class="js-panel-title">Sections</h3>
      <div class="js-panel-content">
        ${renderSections()}
      </div>
    </div>
  `;

    dom.editor.innerHTML = propertiesPanel + sectionsPanel;
  }

  // ==========================================
  // RENDERIZAR SECCIONES
  // ==========================================

  function renderSections() {
    if (
      !state.selectedTemplate.order ||
      state.selectedTemplate.order.length === 0
    ) {
      return '<div class="js-empty">No sections in this template. Add sections from the sidebar.</div>';
    }

    let html = '<div class="js-sections-container">';

    state.selectedTemplate.order.forEach((sectionKey) => {
      const section = state.selectedTemplate.sections[sectionKey];
      if (!section) return;

      const isExpanded = state.expandedSections[sectionKey] || false;
      const sectionType = section.section_id;
      const sectionInfo = state.availableSections[sectionType] || {
        name: sectionType,
      };
      const hasSchema = state.sectionSchemas[sectionType] ? true : false;
      const schemaIndicator = hasSchema ? "⚙️" : "📄";

      html += `
      <div class="js-section" data-id="${sectionKey}">
        <div class="js-section-header">
          <h3 class="js-section-title">
            <span class="js-section-indicator">${schemaIndicator}</span>
            ${escapeHtml(sectionInfo.name || sectionType)}
            <small class="js-template-path">(${escapeHtml(
              sectionType
            )}.twig)</small>
          </h3>
          <div class="js-section-actions">
            <button class="js-button js-button-secondary js-button-sm js-move-up" title="Subir">↑</button>
            <button class="js-button js-button-secondary js-button-sm js-move-down" title="Bajar">↓</button>
            <button class="js-button js-button-danger js-button-sm js-remove-section" title="Eliminar">×</button>
          </div>
        </div>
        
        ${isExpanded ? renderSectionSettings(sectionKey, section) : ""}
      </div>
    `;
    });

    html += "</div>";
    return html;
  }

  // ==========================================
  // RENDERIZAR CONFIGURACIÓN DE SECCIÓN
  // ==========================================

  function renderSectionSettings(sectionKey, section) {
    const sectionType = section.section_id;
    const schema = state.sectionSchemas[sectionType]?.schema || {};
    const sectionProperties = schema.properties || {};
    const blocksDefinition = schema.blocks || {};

    // 🔍 DEBUG - Agregar estos logs
    console.log("=== RENDERIZANDO SETTINGS ===");
    console.log("Section type:", sectionType);
    console.log("Schema encontrado:", state.sectionSchemas[sectionType]);
    console.log("Properties del schema:", sectionProperties);
    console.log(
      "Cantidad de properties:",
      Object.keys(sectionProperties).length
    );

    let html = '<div class="js-section-content">';

    // Renderizar configuraciones de la sección si existen
    if (Object.keys(sectionProperties).length > 0) {
      html += "<h4>Section configuration:</h4>";
      html += '<div class="js-settings-group">';

      Object.entries(sectionProperties).forEach(([key, property]) => {
        // 🔍 DEBUG
        console.log("Renderizando campo:", key, "con property:", property);

        const value =
          section.settings && section.settings[key] !== undefined
            ? section.settings[key]
            : property.default || "";
        html += renderSchemaField(sectionKey, key, property, value, "section");
      });

      html += "</div>";
      html +=
        '<p class="js-form-help"><strong>Acceso en Twig:</strong> <code>{{ section.settings.campo }}</code></p>';
    }

    // Renderizar bloques si existen definiciones
    if (Object.keys(blocksDefinition).length > 0) {
      html += '<div class="js-blocks-section">';
      html += "<h4>Bloques Disponibles:</h4>";

      // Mostrar bloques definidos en el schema
      html += '<div class="js-available-blocks">';
      Object.entries(blocksDefinition).forEach(([blockId, blockDef]) => {
        html += `
        <div class="js-available-block">
          <button 
            class="js-button js-button-secondary js-add-block" 
            data-section="${sectionKey}" 
            data-block-type="${blockId}"
            title="${escapeHtml(
              blockDef.description || "Add block " + blockDef.name
            )}"
          >
            + ${escapeHtml(blockDef.name)}
          </button>
        </div>
      `;
      });
      html += "</div>";

      // Renderizar bloques existentes en la sección
      html += '<div class="js-section-blocks">';
      if (
        section.blocks &&
        Array.isArray(section.blocks) &&
        section.blocks.length > 0
      ) {
        html += "<h5>Blocks in this section:</h5>";

        section.blocks.forEach((block, blockIndex) => {
          const blockType = block.type || block.block_id || "default";
          const blockDefinition = blocksDefinition[blockType] || {
            name: "Undefined block",
            properties: {},
          };

          html += renderBlockSettings(
            sectionKey,
            blockIndex,
            block,
            blockDefinition,
            blocksDefinition
          );
        });
      } else {
        html +=
          '<p class="js-empty-blocks">No blocks added. Use the buttons above to add blocks.</p>';
      }
      html += "</div>";

      html +=
        '<p class="js-form-help"><strong>Twig access:</strong> <code>{% for block in section.blocks %} ... {% endfor %}</code></p>';
      html += "</div>";
    }

    // Si no hay esquema
    if (
      Object.keys(sectionProperties).length === 0 &&
      Object.keys(blocksDefinition).length === 0
    ) {
      html += `
      <div class="js-no-schema">
        <p>This section does not have a schema defined in <code>schemas/${escapeHtml(
          sectionType
        )}.php</code></p>
        <p>The variables will be available in the Twig template as:</p>
        <ul>
          <li><code>{{ section.settings.variable_name }}</code> for settings</li>
          <li><code>{{ section.blocks }}</code> for blocks</li>
        </ul>
        <p>You can create a schema file to generate configuration fields and define blocks automatically.</p>
      </div>
    `;
    }

    html += "</div>";
    return html;
  }

  // ==========================================
  // RENDERIZAR CONFIGURACIÓN DE BLOQUE
  // ==========================================

  function renderBlockSettings(
    sectionKey,
    blockIndex,
    block,
    blockDefinition,
    allBlocksDefinition
  ) {
    const blockType = block.type || block.block_id || "default";
    const blockProperties = blockDefinition.properties || {};
    const blockName = blockDefinition.name || "Bloque";

    let html = `
    <div class="js-block" data-section="${sectionKey}" data-block-index="${blockIndex}">
      <div class="js-block-header">
        <h5 class="js-block-title">
          <span class="js-block-icon">🧱</span>
          ${escapeHtml(blockName)}
          <small class="js-block-type">(${escapeHtml(blockType)})</small>
        </h5>
        <div class="js-block-actions">
          <button class="js-button js-button-secondary js-button-sm js-move-block-up" title="Subir bloque">↑</button>
          <button class="js-button js-button-secondary js-button-sm js-move-block-down" title="Bajar bloque">↓</button>
          <select class="js-block-type-selector" data-section="${sectionKey}" data-block-index="${blockIndex}">
            ${Object.entries(allBlocksDefinition)
              .map(
                ([id, def]) =>
                  `<option value="${escapeHtml(id)}" ${
                    id === blockType ? "selected" : ""
                  }>${escapeHtml(def.name)}</option>`
              )
              .join("")}
          </select>
          <button class="js-button js-button-danger js-button-sm js-remove-block" title="Eliminar bloque">×</button>
        </div>
      </div>
      <div class="js-block-content">
  `;

    // Renderizar campos del bloque
    if (Object.keys(blockProperties).length > 0) {
      Object.entries(blockProperties).forEach(([key, property]) => {
        const value =
          block.settings && block.settings[key] !== undefined
            ? block.settings[key]
            : property.default || "";
        html += renderSchemaField(
          sectionKey,
          key,
          property,
          value,
          "block",
          blockIndex
        );
      });
    } else {
      html +=
        '<p class="js-form-help">This block has no configurable fields defined.</p>';
    }

    html += `
      </div>
    </div>
  `;

    return html;
  }

  // ==========================================
  // ACTUALIZAR CAMPO DE PLANTILLA
  // ==========================================

  function updateTemplateField(field, value) {
    if (!state.selectedTemplate) return;

    state.selectedTemplate[field] = value;

    if (field === "name" && dom.templateTitle) {
      dom.templateTitle.textContent = value || "Untitled";
    }
  }

  // ==========================================
  // AÑADIR SECCIÓN
  // ==========================================

  function addSection(sectionId) {
    if (!state.selectedTemplate) {
      showMessage("error", "No template selected");
      return;
    }

    // CRÍTICO: Validar que sections es un objeto
    if (Array.isArray(state.selectedTemplate.sections)) {
      console.error("❌ sections es array, convirtiendo a objeto");
      state.selectedTemplate.sections = {};
    }

    // Generar ID único para la sección
    const uniqueId = `section_${Date.now()}`;

    console.log("Adding section:", sectionId, "with ID:", uniqueId);

    // NUEVO: Obtener valores por defecto del schema
    const defaultSettings = getSectionDefaultSettings(sectionId);

    console.log(`Default settings for ${sectionId}:`, defaultSettings);

    // **CRÍTICO: Crear nueva sección con settings por defecto**
    state.selectedTemplate.sections[uniqueId] = {
      section_id: sectionId,
      settings: defaultSettings, // ← Settings con valores por defecto
      blocks: [],
    };

    // **CRÍTICO: Verificar que settings es objeto**
    if (Array.isArray(state.selectedTemplate.sections[uniqueId].settings)) {
      console.error("❌ ERROR: settings se creó como array");
      state.selectedTemplate.sections[uniqueId].settings = {};
    }

    // Añadir al orden
    state.selectedTemplate.order.push(uniqueId);

    // Expandir la nueva sección
    state.expandedSections[uniqueId] = true;

    console.log(
      "Section added. Settings is object?:",
      !Array.isArray(state.selectedTemplate.sections[uniqueId].settings)
    );
    console.log(
      "Settings values:",
      state.selectedTemplate.sections[uniqueId].settings
    );

    renderTemplateEditor();
    schedulePreviewRefresh();

    const sectionName = state.availableSections[sectionId]?.name || sectionId;
    showMessage(
      "success",
      `Section "${sectionName}" added with default values`
    );
  }

  // ==========================================
  // OBTENER VALORES POR DEFECTO DE SECCIÓN - NUEVO
  // ==========================================

  function getSectionDefaultSettings(sectionId) {
    const schema = state.sectionSchemas[sectionId]?.schema;

    if (!schema || !schema.properties) {
      console.log(
        `No schema found for section: ${sectionId}, using empty settings`
      );
      return {};
    }

    const defaultSettings = {};
    const properties = schema.properties || {};

    Object.entries(properties).forEach(([key, property]) => {
      // Aplicar valor por defecto si existe
      if (property.default !== undefined) {
        defaultSettings[key] = property.default;
      } else {
        // Si no hay default, usar valor apropiado según el tipo
        defaultSettings[key] = getDefaultValueByType(property.type);
      }
    });

    console.log(
      `Generated default settings for ${sectionId}:`,
      defaultSettings
    );

    return defaultSettings;
  }

  // Helper: Obtener valor por defecto según tipo
  function getDefaultValueByType(type) {
    switch (type) {
      case "number":
        return 0;
      case "boolean":
        return false;
      case "array":
        return [];
      case "object":
        return {};
      default:
        return "";
    }
  }

  // ==========================================
  // REMOVER SECCIÓN
  // ==========================================

  function removeSection(sectionKey) {
    if (!state.selectedTemplate) return;

    const sectionType = state.selectedTemplate.sections[sectionKey]?.section_id;
    const sectionName =
      state.availableSections[sectionType]?.name || sectionType;

    if (!confirm(`Delete section "${sectionName}"?`)) {
      return;
    }

    delete state.selectedTemplate.sections[sectionKey];
    state.selectedTemplate.order = state.selectedTemplate.order.filter(
      (id) => id !== sectionKey
    );

    renderTemplateEditor();
    schedulePreviewRefresh();

    showMessage("success", "Section removed");
  }

  // ==========================================
  // MOVER SECCIÓN ARRIBA
  // ==========================================

  function moveSectionUp(sectionKey) {
    if (!state.selectedTemplate) return;

    const currentIndex = state.selectedTemplate.order.indexOf(sectionKey);
    if (currentIndex <= 0) return;

    const newOrder = [...state.selectedTemplate.order];
    [newOrder[currentIndex], newOrder[currentIndex - 1]] = [
      newOrder[currentIndex - 1],
      newOrder[currentIndex],
    ];
    state.selectedTemplate.order = newOrder;

    renderTemplateEditor();
    schedulePreviewRefresh();
  }

  // ==========================================
  // MOVER SECCIÓN ABAJO
  // ==========================================

  function moveSectionDown(sectionKey) {
    if (!state.selectedTemplate) return;

    const currentIndex = state.selectedTemplate.order.indexOf(sectionKey);
    if (
      currentIndex === -1 ||
      currentIndex >= state.selectedTemplate.order.length - 1
    )
      return;

    const newOrder = [...state.selectedTemplate.order];
    [newOrder[currentIndex], newOrder[currentIndex + 1]] = [
      newOrder[currentIndex + 1],
      newOrder[currentIndex],
    ];
    state.selectedTemplate.order = newOrder;

    renderTemplateEditor();
    schedulePreviewRefresh();
  }

  // ==========================================
  // TOGGLE SECCIÓN (EXPANDIR/CONTRAER)
  // ==========================================

  function toggleSection(sectionKey) {
    state.expandedSections[sectionKey] = !state.expandedSections[sectionKey];
    renderTemplateEditor();
  }

  // ==========================================
  // ACTUALIZAR SETTING DE SECCIÓN
  // ==========================================

  function updateSectionSetting(sectionKey, key, value) {
    if (
      !state.selectedTemplate ||
      !state.selectedTemplate.sections ||
      !state.selectedTemplate.sections[sectionKey]
    ) {
      console.error(`Sección ${sectionKey} no existe`);
      return;
    }

    const section = state.selectedTemplate.sections[sectionKey];

    // **CRÍTICO: Asegurar que settings es un objeto, no un array**
    if (!section.settings || Array.isArray(section.settings)) {
      console.warn(
        `⚠️ Settings de ${sectionKey} era array, convirtiendo a objeto`
      );
      section.settings = {};
    }

    // **CRÍTICO: Si el key es numérico, convertirlo a string para evitar arrays**
    const safeKey = typeof key === "number" ? key.toString() : key;

    // Actualizar el setting con la CLAVE correcta
    section.settings[safeKey] = value;

    console.log(
      `✅ Setting actualizado: ${sectionKey}.settings.${safeKey} =`,
      value
    );
    console.log("Settings completos:", section.settings);

    // Debug: verificar que no sea array
    if (Array.isArray(section.settings)) {
      console.error(
        "❌ ERROR: section.settings sigue siendo array después de la actualización"
      );
    }
  }

  // ==========================================
  // REMOVER BLOQUE
  // ==========================================

  function removeBlock(sectionKey, blockIndex) {
    if (
      !state.selectedTemplate ||
      !state.selectedTemplate.sections ||
      !state.selectedTemplate.sections[sectionKey]
    ) {
      return;
    }

    const section = state.selectedTemplate.sections[sectionKey];
    if (!section.blocks || !section.blocks[blockIndex]) {
      return;
    }

    if (!confirm("Delete this block?")) {
      return;
    }

    section.blocks.splice(blockIndex, 1);
    renderTemplateEditor();
    schedulePreviewRefresh();

    showMessage("success", "Block removed");
  }

  // ==========================================
  // MOVER BLOQUE ARRIBA
  // ==========================================

  function moveBlockUp(sectionKey, blockIndex) {
    if (!state.selectedTemplate || blockIndex <= 0) return;

    const section = state.selectedTemplate.sections[sectionKey];
    if (!section.blocks || !section.blocks[blockIndex]) return;

    [section.blocks[blockIndex], section.blocks[blockIndex - 1]] = [
      section.blocks[blockIndex - 1],
      section.blocks[blockIndex],
    ];

    renderTemplateEditor();
    schedulePreviewRefresh();
  }

  // ==========================================
  // MOVER BLOQUE ABAJO
  // ==========================================

  function moveBlockDown(sectionKey, blockIndex) {
    if (!state.selectedTemplate) return;

    const section = state.selectedTemplate.sections[sectionKey];
    if (!section.blocks || blockIndex >= section.blocks.length - 1) return;

    [section.blocks[blockIndex], section.blocks[blockIndex + 1]] = [
      section.blocks[blockIndex + 1],
      section.blocks[blockIndex],
    ];

    renderTemplateEditor();
    schedulePreviewRefresh();
  }

  // ==========================================
  // CAMBIAR TIPO DE BLOQUE
  // ==========================================

  function changeBlockType(sectionKey, blockIndex, newBlockType) {
    if (!state.selectedTemplate) return;

    const section = state.selectedTemplate.sections[sectionKey];
    if (!section.blocks || !section.blocks[blockIndex]) return;

    // Mantener configuraciones compatibles al cambiar tipo
    const oldBlock = section.blocks[blockIndex];
    section.blocks[blockIndex] = {
      type: newBlockType,
      settings: oldBlock.settings || {},
    };

    renderTemplateEditor();
    schedulePreviewRefresh();

    showMessage("success", `Tipo de bloque cambiado a "${newBlockType}"`);
  }

  // ==========================================
  // ACTUALIZAR SETTING DE BLOQUE
  // ==========================================

  function updateBlockSetting(sectionKey, blockIndex, key, value) {
    if (
      !state.selectedTemplate ||
      !state.selectedTemplate.sections ||
      !state.selectedTemplate.sections[sectionKey]
    ) {
      console.error(`Sección ${sectionKey} no existe`);
      return;
    }

    const section = state.selectedTemplate.sections[sectionKey];
    if (!section.blocks || !section.blocks[blockIndex]) {
      console.error(`Bloque ${blockIndex} no existe en sección ${sectionKey}`);
      return;
    }

    // CRÍTICO: Asegurar que settings del bloque es un objeto
    if (
      !section.blocks[blockIndex].settings ||
      Array.isArray(section.blocks[blockIndex].settings)
    ) {
      console.warn(`⚠️ Settings de bloque era array, convirtiendo a objeto`);
      section.blocks[blockIndex].settings = {};
    }

    // Actualizar con la CLAVE correcta
    section.blocks[blockIndex].settings[key] = value;

    console.log(
      `✅ Block setting actualizado: ${sectionKey}[${blockIndex}].settings.${key} =`,
      value
    );
    console.log(
      "Block settings completos:",
      section.blocks[blockIndex].settings
    );
  }

  // ==========================================
  // RENDERIZAR CAMPO DE SCHEMA
  // ==========================================

  function renderSchemaField(
    sectionKey,
    fieldKey,
    property,
    value,
    context = "section",
    blockIndex = null
  ) {
    const type = property.type || "string";
    const title = property.title || fieldKey;
    const description = property.description || "";

    // Generar IDs únicos
    const fieldId =
      blockIndex !== null
        ? `field-${sectionKey}-${blockIndex}-${fieldKey}`
        : `field-${sectionKey}-${fieldKey}`;
    const previewId = `preview-${fieldId}`;

    // Clases CSS para el input
    const inputClasses =
      context === "block"
        ? "js-form-input js-block-setting-input"
        : "js-form-input js-setting-input";

    let fieldHtml = "";

    // ==========================================
    // CAMPO DE IMAGEN O FILE (UNIFICADO)
    // ==========================================

    if (
      (type === "string" && property.format === "image") ||
      property.format === "file"
    ) {
      const hasMedia = value !== "";
      const mediaType = property.format === "image" ? "image" : "all";

      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <div class="js-image-field">
        <input 
          type="text" 
          id="${fieldId}"
          class="${inputClasses}" 
          value="${escapeHtml(value)}"
          data-setting="${fieldKey}"
          data-type="string"
          data-context="${context}"
          ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
          placeholder="ID del archivo"
          readonly
        />
        <button 
          class="js-button js-button-secondary js-select-media" 
          data-input="${fieldId}" 
          data-preview="${previewId}"
          data-media-type="${mediaType}"
          type="button">
          ${mediaType === "image" ? "📷 Select Image" : "📎 Select File"}
        </button>
      </div>
      <div id="${previewId}" class="js-image-preview" style="${
        hasMedia ? "" : "display: none;"
      }">
        ${
          hasMedia
            ? `<div class="js-media-preview-loading">Loading preview...</div>`
            : ""
        }
        <button 
          class="js-button js-button-danger js-remove-image" 
          data-input="${fieldId}" 
          data-preview="${previewId}"
          type="button">×</button>
      </div>
      <small class="js-form-help">Disponible en Twig como: <code>{{ ${context}.settings.${fieldKey}|attachment_url }}</code></small>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;

      // Si ya hay un valor (ID), cargar preview
      if (hasMedia && value) {
        setTimeout(() => {
          loadAttachmentPreview(previewId, value);
        }, 100);
      }
    }

    // ==========================================
    // CAMPO CODE (CON MODAL EXPANDIBLE)
    // ==========================================
    else if (type === "code" || property.format === "code") {
      const language = property.language || "text";
      const expandable = property.expandable !== false; // Por defecto true

      fieldHtml = `
      <div class="js-form-group">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <textarea 
          id="${fieldId}"
          class="js-form-textarea ${inputClasses.replace(
            "js-form-input",
            "js-form-textarea"
          )}" 
          data-setting="${fieldKey}"
          data-type="string"
          data-context="${context}"
          data-language="${language}"
          ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
          rows="6"
        >${escapeHtml(value)}</textarea>
        ${
          expandable
            ? `
          <button class="js-button js-button-secondary js-expand-code" style="margin-top: 5px;">
            ⛶ Expandir Editor
          </button>
        `
            : ""
        }
        <small class="js-form-help">Disponible en Twig como: <code>{{ ${context}.settings.${fieldKey} }}</code></small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // SELECT CON OPCIONES
    // ==========================================
    else if (property.enum) {
      fieldHtml = `
      <div class="js-form-group">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <select 
          class="js-form-select ${inputClasses.replace(
            "js-form-input",
            "js-form-select"
          )}" 
          data-setting="${fieldKey}" 
          data-type="string"
          data-context="${context}"
          ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
        >
          ${property.enum
            .map((option, idx) => {
              const label = property.enumNames
                ? property.enumNames[idx]
                : option;
              return `<option value="${escapeHtml(option)}" ${
                value === option ? "selected" : ""
              }>${escapeHtml(label)}</option>`;
            })
            .join("")}
        </select>
        <small class="js-form-help">Disponible en Twig como: <code>{{ ${context}.settings.${fieldKey} }}</code></small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // BOOLEAN (CHECKBOX)
    // ==========================================
    else if (type === "boolean") {
      fieldHtml = `
      <div class="js-form-group">
        <label class="js-form-label">
          <input 
            type="checkbox" 
            class="${inputClasses}"
            ${value ? "checked" : ""}
            data-setting="${fieldKey}"
            data-type="boolean"
            data-context="${context}"
            ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
          />
          ${escapeHtml(title)}
        </label>
        <small class="js-form-help">Disponible en Twig como: <code>{{ ${context}.settings.${fieldKey} }}</code></small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // NUMBER
    // ==========================================
    else if (type === "number") {
      const min =
        property.minimum !== undefined ? `min="${property.minimum}"` : "";
      const max =
        property.maximum !== undefined ? `max="${property.maximum}"` : "";
      const step =
        property.multipleOf !== undefined
          ? `step="${property.multipleOf}"`
          : "";

      fieldHtml = `
      <div class="js-form-group">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <input 
          class="${inputClasses}" 
          type="number" 
          value="${value}"
          data-setting="${fieldKey}"
          data-type="number"
          data-context="${context}"
          ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
          ${min} ${max} ${step}
        />
        <small class="js-form-help">Disponible en Twig como: <code>{{ ${context}.settings.${fieldKey} }}</code></small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // COLOR
    // ==========================================
    else if (type === "color" || property.format === "color") {
      fieldHtml = `
      <div class="js-form-group">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <input 
          class="${inputClasses}" 
          type="color" 
          value="${value}"
          data-setting="${fieldKey}"
          data-type="string"
          data-context="${context}"
          ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
        />
        <small class="js-form-help">Disponible en Twig como: <code>{{ ${context}.settings.${fieldKey} }}</code></small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // TEXTAREA
    // ==========================================
    else if (property.format === "textarea") {
      fieldHtml = `
      <div class="js-form-group">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <textarea 
          class="js-form-textarea ${inputClasses.replace(
            "js-form-input",
            "js-form-textarea"
          )}" 
          data-setting="${fieldKey}"
          data-type="string"
          data-context="${context}"
          ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
          rows="4"
        >${escapeHtml(value)}</textarea>
        <small class="js-form-help">Disponible en Twig como: <code>{{ ${context}.settings.${fieldKey} }}</code></small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    // ==========================================
    // HTML / RICHTEXT (Code Editor)
    // ==========================================
    else if (
      type === "html" ||
      type === "richtext" ||
      property.format === "html" ||
      property.format === "richtext"
    ) {
      const language = type === "html" ? "html" : "text";
      const expandable = property.expandable !== false;

      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <textarea 
        id="${fieldId}"
        class="js-form-textarea ${inputClasses.replace(
          "js-form-input",
          "js-form-textarea"
        )}" 
        data-setting="${fieldKey}"
        data-type="string"
        data-context="${context}"
        data-language="${language}"
        ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
        rows="8"
      >${escapeHtml(value)}</textarea>
      ${
        expandable
          ? `
        <button class="js-button js-button-secondary js-expand-code" style="margin-top: 5px;">
          ⛶ Expandir Editor
        </button>
      `
          : ""
      }
      <small class="js-form-help">Disponible en Twig como: <code>{{ ${context}.settings.${fieldKey}|raw }}</code></small>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;
    }

    // ==========================================
    // VIDEO URL
    // ==========================================
    else if (type === "video_url" || property.format === "video_url") {
      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <input 
        class="${inputClasses}" 
        type="url" 
        value="${escapeHtml(value)}"
        data-setting="${fieldKey}"
        data-type="string"
        data-context="${context}"
        ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
        placeholder="https://youtube.com/watch?v=..."
      />
      <small class="js-form-help">📹 Acepta YouTube, Vimeo, etc.</small>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;
    }

    // ==========================================
    // FILE
    // ==========================================
    else if (type === "file" || property.format === "file") {
      const hasFile = value !== "";

      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <div class="js-file-field">
        <input 
          type="text" 
          id="${fieldId}"
          class="${inputClasses}" 
          value="${escapeHtml(value)}"
          data-setting="${fieldKey}"
          data-type="string"
          data-context="${context}"
          ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
          readonly
        />
        <button 
          class="js-button js-button-secondary js-select-image" 
          data-input="${fieldId}"
          type="button">
          📎 Select
        </button>
      </div>
      ${
        hasFile
          ? `<small class="js-form-help">📄 ${escapeHtml(
              value.split("/").pop()
            )}</small>`
          : ""
      }
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;
    }

    // ==========================================
    // SHORTCODE
    // ==========================================
    else if (type === "shortcode" || property.format === "shortcode") {
      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <input 
        class="${inputClasses}" 
        type="text" 
        value="${escapeHtml(value)}"
        data-setting="${fieldKey}"
        data-type="string"
        data-context="${context}"
        ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
        placeholder="[shortcode attr='value']"
        style="font-family: monospace;"
      />
      <small class="js-form-help">📌 Se ejecutará con do_shortcode() en Twig</small>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;
    }

    // ==========================================
    // POST / PAGE / PRODUCT (WordPress Post Types)
    // ==========================================
    else if (["post", "page", "product", "collection", "blog"].includes(type)) {
      // Mapear tipos a post_type de WordPress
      const postTypeMap = {
        post: "post",
        page: "page",
        product: "product",
        collection: "product_cat",
        blog: "post",
      };

      const postType = property.post_type || postTypeMap[type] || "post";
      const typeLabel = type.charAt(0).toUpperCase() + type.slice(1);

      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <select 
        id="${fieldId}"
        class="js-form-select ${inputClasses.replace(
          "js-form-input",
          "js-form-select"
        )} js-post-select" 
        data-setting="${fieldKey}" 
        data-type="string"
        data-context="${context}"
        data-post-type="${postType}"
        ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
      >
        <option value="">-- Select ${typeLabel} --</option>
      </select>
      <small class="js-form-help">🔄 Cargando ${typeLabel}s...</small>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;

      // Cargar posts después de renderizar
      setTimeout(() => {
        loadPostsForSelect(fieldId, postType, value);
      }, 100);
    }

    // ==========================================
    // TAXONOMY (Categorías, Tags, etc)
    // ==========================================
    else if (type === "taxonomy" || property.format === "taxonomy") {
      const taxonomy = property.taxonomy || "category";
      const taxonomyLabel =
        taxonomy.charAt(0).toUpperCase() + taxonomy.slice(1);

      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <select 
        id="${fieldId}"
        class="js-form-select ${inputClasses.replace(
          "js-form-input",
          "js-form-select"
        )} js-taxonomy-select" 
        data-setting="${fieldKey}" 
        data-type="string"
        data-context="${context}"
        data-taxonomy="${taxonomy}"
        ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
      >
        <option value="">-- Select ${taxonomyLabel} --</option>
      </select>
      <small class="js-form-help">🔄 Cargando términos...</small>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;

      // Cargar términos después de renderizar
      setTimeout(() => {
        loadTermsForSelect(fieldId, taxonomy, value);
      }, 100);
    }

    // ==========================================
    // MENU (WordPress Nav Menus)
    // ==========================================
    else if (type === "menu" || property.format === "menu") {
      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <select 
        id="${fieldId}"
        class="js-form-select ${inputClasses.replace(
          "js-form-input",
          "js-form-select"
        )} js-menu-select" 
        data-setting="${fieldKey}" 
        data-type="string"
        data-context="${context}"
        ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
      >
        <option value="">-- Select Menu --</option>
      </select>
      <small class="js-form-help">🔄 Cargando menús...</small>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;

      // Cargar menús después de renderizar
      setTimeout(() => {
        loadMenusForSelect(fieldId, value);
      }, 100);
    }

    // ==========================================
    // WIDGET / SIDEBAR
    // ==========================================
    else if (type === "widget" || property.format === "widget") {
      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <select 
        id="${fieldId}"
        class="js-form-select ${inputClasses.replace(
          "js-form-input",
          "js-form-select"
        )} js-sidebar-select" 
        data-setting="${fieldKey}" 
        data-type="string"
        data-context="${context}"
        ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
      >
        <option value="">-- Select Sidebar --</option>
      </select>
      <small class="js-form-help">🔄 Cargando sidebars...</small>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;

      // Cargar sidebars después de renderizar
      setTimeout(() => {
        loadSidebarsForSelect(fieldId, value);
      }, 100);
    }

    // ==========================================
    // LINK_LIST (Múltiples URLs)
    // ==========================================
    else if (type === "link_list" || property.format === "link_list") {
      // Por ahora, implementación simple con textarea JSON
      fieldHtml = `
    <div class="js-form-group">
      <label class="js-form-label">${escapeHtml(title)}</label>
      <textarea 
        class="js-form-textarea ${inputClasses.replace(
          "js-form-input",
          "js-form-textarea"
        )}" 
        data-setting="${fieldKey}"
        data-type="string"
        data-context="${context}"
        ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
        rows="4"
        placeholder='{"label": "Inicio", "url": "/"}'
      >${escapeHtml(value)}</textarea>
      <small class="js-form-help">🔗 Un enlace por línea en formato JSON</small>
      ${
        description
          ? `<p class="js-form-help">${escapeHtml(description)}</p>`
          : ""
      }
    </div>
  `;
    }

    // ==========================================
    // TEXT INPUT (DEFAULT)
    // ==========================================
    else {
      const placeholder = property.placeholder
        ? `placeholder="${escapeHtml(property.placeholder)}"`
        : "";

      fieldHtml = `
      <div class="js-form-group">
        <label class="js-form-label">${escapeHtml(title)}</label>
        <input 
          class="${inputClasses}" 
          type="text" 
          value="${escapeHtml(value)}"
          data-setting="${fieldKey}"
          data-type="string"
          data-context="${context}"
          ${blockIndex !== null ? `data-block-index="${blockIndex}"` : ""}
          ${placeholder}
        />
        <small class="js-form-help">Disponible en Twig como: <code>{{ ${context}.settings.${fieldKey} }}</code></small>
        ${
          description
            ? `<p class="js-form-help">${escapeHtml(description)}</p>`
            : ""
        }
      </div>
    `;
    }

    return fieldHtml;
  }

  function isNumericKey(key) {
    return !isNaN(key) && key !== "" && !isNaN(parseFloat(key));
  }

  // ==========================================
  // SELECCIONAR MEDIA DE WORDPRESS (UNIFICADO: IMAGE + FILE)
  // ==========================================

  function selectWordPressMedia(button) {
    // Verify WordPress Media Library availability
    if (!window.wp || !window.wp.media) {
      console.error("WordPress Media Library is not available");
      showMessage("error", "Error: WordPress Media Library not available");
      return;
    }

    const inputId = button.getAttribute("data-input");
    const previewId = button.getAttribute("data-preview");
    const mediaType = button.getAttribute("data-media-type") || "all"; // 'image', 'file', 'all'

    // Configurar frame según tipo
    const frameConfig = {
      title: mediaType === "image" ? "Select image" : "Select file",
      button: { text: mediaType === "image" ? "Use image" : "Use file" },
      multiple: false,
    };

    // Filtrar por tipo si es necesario
    if (mediaType === "image") {
      frameConfig.library = { type: "image" };
    }

    // Crear frame de WordPress Media
    const frame = wp.media(frameConfig);

    // Cuando se selecciona un archivo
    frame.on("select", function () {
      const attachment = frame.state().get("selection").first().toJSON();

      console.log("📎 Attachment seleccionado:", attachment);

      // **CRÍTICO: Guardar solo el ID**
      const input = document.getElementById(inputId);
      if (input) {
        input.value = attachment.id; // ← Solo ID, no URL

        // Trigger change event
        const event = new Event("change", { bubbles: true });
        input.dispatchEvent(event);
      }

      // Actualizar preview
      updateMediaPreview(previewId, attachment);
    });

    // Abrir el frame
    frame.open();
  }

  // ==========================================
  // ACTUALIZAR PREVIEW DE MEDIA
  // ==========================================

  function updateMediaPreview(previewId, attachment) {
    const preview = document.getElementById(previewId);
    if (!preview) return;

    console.log("preview", preview);

    // Limpiar preview anterior
    const oldContent = preview.querySelector(".js-media-preview-content");
    if (oldContent) oldContent.remove();

    const loadingMessagePreview = preview.querySelector(
      ".js-media-preview-loading"
    );
    if (loadingMessagePreview) loadingMessagePreview.style.display = "none";

    // Crear nuevo contenedor
    const content = document.createElement("div");
    content.className = "js-media-preview-content";

    // Determinar tipo de archivo
    const isImage = attachment.type === "image";
    const fileInfo = {
      filename: attachment.filename,
      filesize: attachment.filesizeHumanReadable || "",
      url: attachment.url,
    };

    if (isImage) {
      // Preview de imagen
      content.innerHTML = `
      <img src="${escapeHtml(attachment.url)}" alt="${escapeHtml(
        attachment.alt || ""
      )}" />
      <div class="js-media-info">
        <small>${escapeHtml(fileInfo.filename)}</small>
      </div>
    `;
    } else {
      // Preview de archivo (icono + info)
      const icon = getFileIcon(attachment.subtype || attachment.mime);
      content.innerHTML = `
      <div class="js-file-preview">
        <span class="js-file-icon">${icon}</span>
        <div class="js-file-info">
          <strong>${escapeHtml(fileInfo.filename)}</strong>
          <small>${escapeHtml(fileInfo.filesize)}</small>
        </div>
      </div>
    `;
    }

    preview.insertBefore(content, preview.firstChild);
    preview.style.display = "block";
  }

  // ==========================================
  // CARGAR PREVIEW DESDE ID (AJAX)
  // ==========================================

  async function loadAttachmentPreview(previewId, attachmentId) {
    const preview = document.getElementById(previewId);
    if (!preview) return;

    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_attachment_data",
          nonce: config.nonce,
          attachment_id: attachmentId,
        }),
      });

      const data = await response.json();

      if (data.success && data.data) {
        updateMediaPreview(previewId, data.data);
      } else {
        // Fallback: mostrar solo ID
        const loading = preview.querySelector(".js-media-preview-loading");
        if (loading) {
          loading.textContent = `ID: ${attachmentId} (no se pudo cargar preview)`;
        }
      }
    } catch (error) {
      console.error("Error loading attachment preview:", error);
    }
  }

  // ==========================================
  // HELPER: ICONO SEGÚN TIPO DE ARCHIVO
  // ==========================================

  function getFileIcon(mimeType) {
    const icons = {
      pdf: "📄",
      zip: "📦",
      doc: "📝",
      docx: "📝",
      xls: "📊",
      xlsx: "📊",
      ppt: "📽️",
      pptx: "📽️",
      mp4: "🎥",
      mp3: "🎵",
      default: "📎",
    };

    const extension = mimeType ? mimeType.split("/").pop() : "";
    return icons[extension] || icons.default;
  }

  // ==========================================
  // REMOVER IMAGEN SELECCIONADA
  // ==========================================

  function removeSelectedImage(button) {
    const inputId = button.getAttribute("data-input");
    const previewId = button.getAttribute("data-preview");

    // Limpiar input
    const input = document.getElementById(inputId);
    if (input) {
      input.value = "";

      // Trigger change event
      const event = new Event("change", { bubbles: true });
      input.dispatchEvent(event);
    }

    // Ocultar y limpiar preview
    const preview = document.getElementById(previewId);
    if (preview) {
      preview.style.display = "none";
      const img = preview.querySelector("img");
      if (img) img.remove();
    }

    schedulePreviewRefresh();
  }

  // ==========================================
  // ESCAPE HTML (PREVENIR XSS)
  // ==========================================

  function escapeHtml(text) {
    if (typeof text !== "string") {
      text = String(text);
    }

    const map = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    };

    return text.replace(/[&<>"']/g, (m) => map[m]);
  }

  // ==========================================
  // DEBOUNCE (PARA OPTIMIZAR EVENTOS)
  // ==========================================

  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // ==========================================
  // DEEP CLONE (CLONAR OBJETOS)
  // ==========================================

  function deepClone(obj) {
    try {
      return JSON.parse(JSON.stringify(obj));
    } catch (e) {
      console.error("Error clonando objeto:", e);
      return obj;
    }
  }

  // ==========================================
  // VALIDAR NOMBRE DE ARCHIVO
  // ==========================================

  function isValidFileName(name) {
    const validNameRegex = /^[a-zA-Z0-9_-]+$/;
    return validNameRegex.test(name);
  }

  // ==========================================
  // FORMATEAR FECHA
  // ==========================================

  function formatDate(date) {
    if (!(date instanceof Date)) {
      date = new Date(date);
    }

    const options = {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    };

    return date.toLocaleDateString("es-ES", options);
  }

  // ==========================================
  // GENERAR ID ÚNICO
  // ==========================================

  function generateUniqueId(prefix = "id") {
    return `${prefix}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  }

  // ==========================================
  // VALIDAR JSON
  // ==========================================

  function isValidJSON(str) {
    try {
      JSON.parse(str);
      return true;
    } catch (e) {
      return false;
    }
  }

  // ==========================================
  // OBTENER VALOR ANIDADO DE OBJETO
  // ==========================================

  function getNestedValue(obj, path) {
    return path.split(".").reduce((current, key) => current?.[key], obj);
  }

  // ==========================================
  // ESTABLECER VALOR ANIDADO DE OBJETO
  // ==========================================

  function setNestedValue(obj, path, value) {
    const keys = path.split(".");
    const lastKey = keys.pop();
    const target = keys.reduce((current, key) => {
      if (!current[key]) current[key] = {};
      return current[key];
    }, obj);
    target[lastKey] = value;
  }

  // ==========================================
  // LOGGING CON NIVELES (OPCIONAL)
  // ==========================================

  const logger = {
    debug: (...args) => {
      if (window.juztStudioDebug) {
        console.log("[Juzt Studio Debug]", ...args);
      }
    },

    info: (...args) => {
      console.info("[Juzt Studio]", ...args);
    },

    warn: (...args) => {
      console.warn("[Juzt Studio Warning]", ...args);
    },

    error: (...args) => {
      console.error("[Juzt Studio Error]", ...args);
    },

    group: (label) => {
      if (window.juztStudioDebug) {
        console.group("[Juzt Studio] " + label);
      }
    },

    groupEnd: () => {
      if (window.juztStudioDebug) {
        console.groupEnd();
      }
    },
  };

  // ==========================================
  // EXPORTAR STATE PARA DEBUG (OPCIONAL)
  // ==========================================

  function exportStateForDebug() {
    const debugData = {
      selectedTemplate: state.selectedTemplate,
      generalSettings: state.generalSettings,
      availableSections: Object.keys(state.availableSections),
      sectionSchemas: Object.keys(state.sectionSchemas),
      timestamp: new Date().toISOString(),
    };

    console.log("=== Juzt Studio State Debug ===");
    console.log(JSON.stringify(debugData, null, 2));

    return debugData;
  }

  /**
   * Cargar posts para select
   */
  async function loadPostsForSelect(selectId, postType, selectedValue) {
    const select = document.getElementById(selectId);
    if (!select) return;

    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "search_posts",
          nonce: config.nonce,
          post_type: postType,
          limit: 100,
        }),
      });

      const data = await response.json();

      if (data.success) {
        const typeLabel = postType.charAt(0).toUpperCase() + postType.slice(1);
        select.innerHTML = `<option value="">-- Select ${typeLabel} --</option>`;

        data.data.forEach((post) => {
          const option = document.createElement("option");
          option.value = post.id;
          option.textContent = post.title;
          if (post.id == selectedValue) {
            option.selected = true;
          }
          select.appendChild(option);
        });

        // Update message
        const helpText = select.parentElement.querySelector(".js-form-help");
        if (helpText) {
          helpText.textContent = `✅ ${data.data.length} ${typeLabel}(s) available`;
        }

        console.log(`✅ Loaded ${data.data.length} posts of type ${postType}`);
      }
    } catch (error) {
      console.error("Error loading posts:", error);
      const helpText = select.parentElement.querySelector(".js-form-help");
      if (helpText) {
        helpText.textContent = `❌ Error loading posts`;
      }
    }
  }

  /**
   * Cargar términos de taxonomía para select
   */
  async function loadTermsForSelect(selectId, taxonomy, selectedValue) {
    const select = document.getElementById(selectId);
    if (!select) return;

    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "search_terms",
          nonce: config.nonce,
          taxonomy: taxonomy,
        }),
      });

      const data = await response.json();

      if (data.success) {
        const taxonomyLabel =
          taxonomy.charAt(0).toUpperCase() + taxonomy.slice(1);
        select.innerHTML = `<option value="">-- Select ${taxonomyLabel} --</option>`;

        data.data.forEach((term) => {
          const option = document.createElement("option");
          option.value = term.id;
          option.textContent = `${term.name} (${term.count})`;
          if (term.id == selectedValue) {
            option.selected = true;
          }
          select.appendChild(option);
        });

        const helpText = select.parentElement.querySelector(".js-form-help");
        if (helpText) {
          helpText.textContent = `✅ ${data.data.length} term(s) available`;
        }

        console.log(`✅ Loaded ${data.data.length} terms of ${taxonomy}`);
      }
    } catch (error) {
      console.error("Error loading terms:", error);
    }
  }

  /**
   * Cargar menús para select
   */
  async function loadMenusForSelect(selectId, selectedValue) {
    const select = document.getElementById(selectId);
    if (!select) return;

    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_menus",
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        select.innerHTML = '<option value="">-- Select Menu --</option>';

        data.data.forEach((menu) => {
          const option = document.createElement("option");
          option.value = menu.id;
          option.textContent = `${menu.name} (${menu.count} items)`;
          if (menu.id == selectedValue) {
            option.selected = true;
          }
          select.appendChild(option);
        });

        const helpText = select.parentElement.querySelector(".js-form-help");
        if (helpText) {
          helpText.textContent = `✅ ${data.data.length} menú(s) disponibles`;
        }

        console.log(`✅ Cargados ${data.data.length} menús`);
      }
    } catch (error) {
      console.error("Error loading menus:", error);
    }
  }

  /**
   * Cargar sidebars para select
   */
  async function loadSidebarsForSelect(selectId, selectedValue) {
    const select = document.getElementById(selectId);
    if (!select) return;

    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "get_sidebars",
          nonce: config.nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        select.innerHTML = '<option value="">-- Select Sidebar --</option>';

        data.data.forEach((sidebar) => {
          const option = document.createElement("option");
          option.value = sidebar.id;
          option.textContent = sidebar.name;
          if (sidebar.id == selectedValue) {
            option.selected = true;
          }
          select.appendChild(option);
        });

        const helpText = select.parentElement.querySelector(".js-form-help");
        if (helpText) {
          helpText.textContent = `✅ ${data.data.length} sidebar(s) disponibles`;
        }

        console.log(`✅ Cargados ${data.data.length} sidebars`);
      }
    } catch (error) {
      console.error("Error loading sidebars:", error);
    }
  }

  // Exponer en window para debug (solo en desarrollo)
  if (window.juztStudioDebug) {
    window.juztStudioDebug = {
      state,
      config,
      exportState: exportStateForDebug,
      renderTemplateEditor,
      renderGeneralSettingsPanel,
    };
  }

  // ==========================================
  // EXPONER API PÚBLICA (SI ES NECESARIO)
  // ==========================================

  // Exponer algunas funciones en window si otros scripts las necesitan
  window.JuztStudio = {
    version: "1.0.0",
    refresh: renderTemplateEditor,
    refreshPreview: refreshPreview,
    saveTemplate: saveTemplate,
    saveGeneralSettings: saveGeneralSettings,
  };

  // ==========================================
  // INICIALIZAR LA APP
  // ==========================================

  init();
})();
