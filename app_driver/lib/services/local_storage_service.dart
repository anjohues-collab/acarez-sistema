import 'dart:async';
import 'dart:convert';
import 'package:sqflite/sqflite.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart';
import 'package:shared_preferences/shared_preferences.dart';

class LocalStorageService {
  static final LocalStorageService _instance = LocalStorageService._internal();
  factory LocalStorageService() => _instance;
  LocalStorageService._internal();

  Database? _database;
  SharedPreferences? _prefs;
  static const String _keyRutaActiva = 'ruta_activa_id';

  // ========== INICIALIZAR ==========
  Future<void> init() async {
    _prefs = await SharedPreferences.getInstance();
    _database = await _initDatabase();
    print('✅ LocalStorageService inicializado');
  }

  Future<Database> _initDatabase() async {
    final documentsDirectory = await getApplicationDocumentsDirectory();
    final path = join(documentsDirectory.path, 'acarez_driver.db');
    
    return await openDatabase(
      path,
      version: 5, // 👈 Incrementado a 5 para soportar observaciones en cucas_pendientes
      onCreate: _onCreate,
      onUpgrade: _onUpgrade,
    );
  }

  Future<void> _onCreate(Database db, int version) async {
    // Tabla: Ruta en progreso
    await db.execute('''
      CREATE TABLE IF NOT EXISTS ruta_progreso (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ruta_id INTEGER NOT NULL,
        chofer TEXT NOT NULL,
        placas TEXT NOT NULL,
        no_economico TEXT NOT NULL,
        origen_real TEXT NOT NULL,
        ubicacion_gps TEXT NOT NULL,
        km_inicial REAL NOT NULL,
        foto_inicio TEXT NOT NULL,
        fecha_inicio TEXT NOT NULL,
        paradas_completadas TEXT,
        sincronizado INTEGER DEFAULT 0
      )
    ''');

    // Tabla: Gastos pendientes
    await db.execute('''
      CREATE TABLE IF NOT EXISTS gastos_pendientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ruta_id INTEGER NOT NULL,
        parada_id INTEGER NOT NULL,
        destino TEXT NOT NULL,
        tipo_gasto TEXT,
        monto REAL DEFAULT 0,
        observaciones TEXT,
        foto_comprobante TEXT,
        gastos TEXT,
        fotos TEXT,
        fecha TEXT NOT NULL,
        completada INTEGER DEFAULT 0,
        sincronizado INTEGER DEFAULT 0
      )
    ''');

    // Tabla: Cucas / Facturas pendientes
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cucas_pendientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ruta_id INTEGER NOT NULL,
        parada_id INTEGER NOT NULL,
        numero_cuca TEXT NOT NULL,
        observaciones TEXT,
        foto_cuca TEXT NOT NULL,
        fecha TEXT NOT NULL,
        sincronizado INTEGER DEFAULT 0
      )
    ''');

    // Tabla: Ruta finalizada
    await db.execute('''
      CREATE TABLE IF NOT EXISTS ruta_finalizada (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ruta_id INTEGER NOT NULL,
        km_final REAL NOT NULL,
        foto_fin TEXT NOT NULL,
        destino_final TEXT NOT NULL,
        fecha_fin TEXT NOT NULL,
        sincronizado INTEGER DEFAULT 0
      )
    ''');

    // Columns fallback
    try {
      await db.execute('ALTER TABLE gastos_pendientes ADD COLUMN tipo_gasto TEXT');
    } catch (e) {}
    
    try {
      await db.execute('ALTER TABLE gastos_pendientes ADD COLUMN monto REAL DEFAULT 0');
    } catch (e) {}
    
    try {
      await db.execute('ALTER TABLE gastos_pendientes ADD COLUMN observaciones TEXT');
    } catch (e) {}
    
    try {
      await db.execute('ALTER TABLE gastos_pendientes ADD COLUMN foto_comprobante TEXT');
    } catch (e) {}

    try {
      await db.execute('ALTER TABLE cucas_pendientes ADD COLUMN observaciones TEXT');
    } catch (e) {}

    print('✅ Base de datos actualizada correctamente');
  }
  
  Future<void> _onUpgrade(Database db, int oldVersion, int newVersion) async {
    print('🔄 Actualizando base de datos de versión $oldVersion a $newVersion');
    
    if (oldVersion < 5) {
      try {
        await db.execute('ALTER TABLE cucas_pendientes ADD COLUMN observaciones TEXT');
        print('✅ Columna observaciones agregada a cucas_pendientes');
      } catch (e) {
        print('⚠️ Error agregando observaciones a cucas_pendientes: $e');
      }
    }
  }

  // ========== GUARDAR RUTA INICIADA ==========
  Future<int> guardarRutaIniciada(Map<String, dynamic> data) async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return 0;
    
    await guardarRutaActiva(data['ruta_id']);
    return await db.insert('ruta_progreso', data);
  }

  // ========== OBTENER RUTA EN PROGRESO ==========
  Future<Map<String, dynamic>?> obtenerRutaEnProgreso() async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return null;
    
    final List<Map<String, dynamic>> maps = await db.query(
      'ruta_progreso',
      where: 'sincronizado = 0',
      orderBy: 'id DESC',
      limit: 1,
    );
    if (maps.isNotEmpty) {
      return maps.first;
    }
    return null;
  }

  // ========== CONTROL DE PARADAS COMPLETADAS ==========

  Future<void> marcarParadaCompletada(int rutaId, int destinoId) async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return;

    final List<Map<String, dynamic>> result = await db.query(
      'ruta_progreso',
      where: 'ruta_id = ?',
      whereArgs: [rutaId],
    );

    if (result.isNotEmpty) {
      List<dynamic> paradas = [];
      final rawParadas = result.first['paradas_completadas'];
      if (rawParadas != null && rawParadas.toString().isNotEmpty) {
        try {
          paradas = json.decode(rawParadas.toString());
        } catch (e) {
          paradas = [];
        }
      }

      if (!paradas.contains(destinoId)) {
        paradas.add(destinoId);
        await db.update(
          'ruta_progreso',
          {'paradas_completadas': json.encode(paradas)},
          where: 'ruta_id = ?',
          whereArgs: [rutaId],
        );
      }
    }
  }

  Future<List<int>> obtenerParadasCompletadasLocal(int rutaId) async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return [];

    final List<Map<String, dynamic>> result = await db.query(
      'ruta_progreso',
      columns: ['paradas_completadas'],
      where: 'ruta_id = ?',
      whereArgs: [rutaId],
    );

    if (result.isNotEmpty && result.first['paradas_completadas'] != null) {
      final rawParadas = result.first['paradas_completadas'].toString();
      if (rawParadas.isNotEmpty) {
        try {
          List<dynamic> list = json.decode(rawParadas);
          return list.map((e) => int.parse(e.toString())).toList();
        } catch (e) {
          return [];
        }
      }
    }
    return [];
  }

  // ========== GUARDAR GASTO PENDIENTE ==========
  Future<int> guardarGastoPendiente(Map<String, dynamic> data) async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return 0;
    
    final Map<String, dynamic> datos = {
      'ruta_id': data['ruta_id'],
      'parada_id': data['parada_id'],
      'destino': data['destino'] ?? '',
      'tipo_gasto': data['tipo_gasto'] ?? '',
      'monto': data['monto'] ?? 0.0,
      'observaciones': data['observaciones'] ?? '',
      'foto_comprobante': data['foto_comprobante'] ?? '',
      'gastos': data['gastos'] != null ? json.encode(data['gastos']) : '{}',
      'fotos': data['fotos'] != null ? json.encode(data['fotos']) : '{}',
      'fecha': data['fecha'] ?? DateTime.now().toIso8601String(),
      'completada': data['completada'] ?? 0,
      'sincronizado': data['sincronizado'] ?? 0,
    };
    
    return await db.insert('gastos_pendientes', datos);
  }

  // ========== OBTENER GASTOS PENDIENTES ==========
  Future<List<Map<String, dynamic>>> obtenerGastosPendientes() async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return [];
    
    return await db.query(
      'gastos_pendientes',
      where: 'sincronizado = 0',
    );
  }

  // ========== GUARDAR CUCA PENDIENTE ==========
  Future<int> guardarCucaPendiente(Map<String, dynamic> data) async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return 0;
    
    final Map<String, dynamic> datos = {
      'ruta_id': data['ruta_id'],
      'parada_id': data['parada_id'],
      'numero_cuca': data['numero_cuca'] ?? '',
      'observaciones': data['observaciones'] ?? '', // 👈 AHORA SÍ GUARDA LAS OBSERVACIONES LOCALMENTE
      'foto_cuca': data['foto_cuca'] ?? '',
      'fecha': data['fecha'] ?? DateTime.now().toIso8601String(),
      'sincronizado': data['sincronizado'] ?? 0,
    };
    
    return await db.insert('cucas_pendientes', datos);
  }

  // ========== OBTENER CUCAS PENDIENTES ==========
  Future<List<Map<String, dynamic>>> obtenerCucasPendientes() async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return [];
    
    return await db.query(
      'cucas_pendientes',
      where: 'sincronizado = 0',
    );
  }

  // ========== GUARDAR RUTA FINALIZADA ==========
  Future<int> guardarRutaFinalizada(Map<String, dynamic> data) async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return 0;
    
    await borrarRutaActiva();
    return await db.insert('ruta_finalizada', data);
  }

  // ========== OBTENER RUTAS FINALIZADAS PENDIENTES ==========
  Future<List<Map<String, dynamic>>> obtenerRutasFinalizadasPendientes() async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return [];
    
    return await db.query(
      'ruta_finalizada',
      where: 'sincronizado = 0',
    );
  }

  // ========== GUARDAR RUTA ACTIVA (SharedPreferences) ==========
  Future<void> guardarRutaActiva(int rutaId) async {
    if (_prefs == null) await init();
    await _prefs!.setInt(_keyRutaActiva, rutaId);
  }

  // ========== OBTENER RUTA ACTIVA ==========
  Future<int?> obtenerRutaActivaId() async {
    if (_prefs == null) await init();
    return _prefs!.getInt(_keyRutaActiva);
  }

  // ========== BORRAR RUTA ACTIVA ==========
  Future<void> borrarRutaActiva() async {
    if (_prefs == null) await init();
    await _prefs!.remove(_keyRutaActiva);
  }

  // ========== MARCAR COMO SINCRONIZADO ==========
  Future<void> marcarSincronizado(String tabla, int id) async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return;
    
    await db.update(
      tabla,
      {'sincronizado': 1},
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  // ========== ELIMINAR DATOS SINCRONIZADOS ==========
  Future<void> limpiarDatosSincronizados() async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return;
    
    await db.delete('ruta_progreso', where: 'sincronizado = 1');
    await db.delete('gastos_pendientes', where: 'sincronizado = 1');
    await db.delete('cucas_pendientes', where: 'sincronizado = 1');
    await db.delete('ruta_finalizada', where: 'sincronizado = 1');
    await borrarRutaActiva();
  }

  // ========== CONTAR DATOS PENDIENTES ==========
  Future<int> contarDatosPendientes() async {
    if (_database == null) await init();
    final db = _database;
    if (db == null) return 0;
    
    final List<Map<String, dynamic>> result = await db.rawQuery('''
      SELECT COUNT(*) as total FROM ruta_progreso WHERE sincronizado = 0
    ''');
    return result.first['total'] as int? ?? 0;
  }
}
