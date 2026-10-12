import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import '../services/local_storage_service.dart';

class SincronizacionScreen extends StatefulWidget {
  final String baseUrl;

  const SincronizacionScreen({Key? key, required this.baseUrl}) : super(key: key);

  @override
  State<SincronizacionScreen> createState() => _SincronizacionScreenState();
}

class _SincronizacionScreenState extends State<SincronizacionScreen> {
  bool _sincronizando = false;
  
  Map<String, dynamic>? _rutaInicio;
  List<Map<String, dynamic>> _gastos = [];
  List<Map<String, dynamic>> _cucas = [];
  List<Map<String, dynamic>> _rutasFin = [];

  @override
  void initState() {
    super.initState();
    _cargarPendientes();
  }

  Future<void> _cargarPendientes() async {
    final ruta = await LocalStorageService().obtenerRutaEnProgreso();
    final gastos = await LocalStorageService().obtenerGastosPendientes();
    final cucas = await LocalStorageService().obtenerCucasPendientes();
    final rutasFin = await LocalStorageService().obtenerRutasFinalizadasPendientes();

    if (mounted) {
      setState(() {
        _rutaInicio = ruta;
        _gastos = gastos;
        _cucas = cucas;
        _rutasFin = rutasFin;
      });
    }
  }

  bool _hayDatosPendientes() {
    return _rutaInicio != null || _gastos.isNotEmpty || _cucas.isNotEmpty || _rutasFin.isNotEmpty;
  }

  Future<void> _sincronizarTodo() async {
    setState(() => _sincronizando = true);
    int exitos = 0;
    int errores = 0;

    try {
      final String baseLimpia = widget.baseUrl.endsWith('/') 
          ? widget.baseUrl.substring(0, widget.baseUrl.length - 1) 
          : widget.baseUrl;

      // 1. Sincronizar Inicio de Ruta
      if (_rutaInicio != null) {
        try {
          var reqInicio = http.MultipartRequest('POST', Uri.parse('$baseLimpia/api/iniciar_ruta_chofer.php'));
          reqInicio.fields['ruta_id'] = _rutaInicio!['ruta_id'].toString();
          reqInicio.fields['km_inicial'] = _rutaInicio!['km_inicial'].toString();
          reqInicio.fields['placas'] = _rutaInicio!['placas'].toString();
          reqInicio.fields['no_economico'] = _rutaInicio!['no_economico'].toString();
          reqInicio.fields['origen_real'] = _rutaInicio!['origen_real'].toString();
          reqInicio.fields['ubicacion_gps'] = _rutaInicio!['ubicacion_gps'].toString();

          if (_rutaInicio!['foto_inicio'] != null && _rutaInicio!['foto_inicio'].toString().isNotEmpty) {
            File foto = File(_rutaInicio!['foto_inicio']);
            if (await foto.exists()) {
              reqInicio.files.add(await http.MultipartFile.fromPath('foto_inicio', foto.path));
            }
          }

          var resInicio = await reqInicio.send();
          if (resInicio.statusCode == 200) {
            await LocalStorageService().marcarSincronizado('ruta_progreso', _rutaInicio!['id']);
            exitos++;
          } else {
            errores++;
          }
        } catch (e) {
          errores++;
        }
      }

      // 2. Agrupar Gastos y Cucas por parada para enviarlos juntos
      Map<String, Map<String, dynamic>> paradasData = {};

      for (var g in _gastos) {
        String key = "${g['ruta_id']}_${g['parada_id']}";
        paradasData.putIfAbsent(key, () => {'ruta_id': g['ruta_id'], 'parada_id': g['parada_id'], 'gastos': [], 'cucas': []});
        paradasData[key]!['gastos'].add(g);
      }
      
      for (var c in _cucas) {
        String key = "${c['ruta_id']}_${c['parada_id']}";
        paradasData.putIfAbsent(key, () => {'ruta_id': c['ruta_id'], 'parada_id': c['parada_id'], 'gastos': [], 'cucas': []});
        paradasData[key]!['cucas'].add(c);
      }

      // 3. Enviar Paradas Agrupadas
      for (var parada in paradasData.values) {
        try {
          var reqParada = http.MultipartRequest('POST', Uri.parse('$baseLimpia/api/agregar_parada_chofer.php'));
          reqParada.fields['ruta_id'] = parada['ruta_id'].toString();
          reqParada.fields['parada_id'] = parada['parada_id'].toString();

          List<dynamic> gastosParada = parada['gastos'];
          for (var g in gastosParada) {
            String tipo = g['tipo_gasto'].toString();
            String keyGasto = 'comida';
            if (tipo.contains('Hotel')) keyGasto = 'hotel';
            else if (tipo.contains('Caseta')) keyGasto = 'caseta';
            else if (tipo.contains('Estacionamiento')) keyGasto = 'estacionamiento';
            else if (tipo.contains('Gasolina') || tipo.contains('Diesel')) keyGasto = 'gasolina';

            reqParada.fields['gasto_$keyGasto'] = g['monto'].toString();
            reqParada.fields['metodo_pago_$keyGasto'] = g['metodo_pago']?.toString() ?? 'Efectivo';
            
            // 👈 OBSERVACIONES DE GASTO ENVIADAS AL SERVIDOR
            reqParada.fields['observaciones_$keyGasto'] = g['observaciones']?.toString() ?? '';

            if (g['foto_comprobante'] != null && g['foto_comprobante'].toString().isNotEmpty) {
              File foto = File(g['foto_comprobante']);
              if (await foto.exists()) {
                reqParada.files.add(await http.MultipartFile.fromPath('foto_$keyGasto', foto.path));
              }
            }
          }

          List<dynamic> cucasParada = parada['cucas'];
          for (int i = 0; i < cucasParada.length; i++) {
            var c = cucasParada[i];
            reqParada.fields['numero_cuca_$i'] = c['numero_cuca'].toString();
            
            // 👈 OBSERVACIONES DE CUCA ENVIADAS AL SERVIDOR
            reqParada.fields['observaciones_cuca_$i'] = c['observaciones']?.toString() ?? '';
            
            if (c['foto_cuca'] != null && c['foto_cuca'].toString().isNotEmpty) {
              File foto = File(c['foto_cuca']);
              if (await foto.exists()) {
                reqParada.files.add(await http.MultipartFile.fromPath('foto_cuca_$i', foto.path));
              }
            }
          }

          var resParada = await reqParada.send();
          if (resParada.statusCode == 200) {
            for (var g in gastosParada) await LocalStorageService().marcarSincronizado('gastos_pendientes', g['id']);
            for (var c in cucasParada) await LocalStorageService().marcarSincronizado('cucas_pendientes', c['id']);
            exitos++;
          } else {
            errores++;
          }
        } catch (e) {
          errores++;
        }
      }

      // 4. Sincronizar Finalización de Rutas
      for (var fin in _rutasFin) {
        try {
          var reqFin = http.MultipartRequest('POST', Uri.parse('$baseLimpia/api/finalizar_ruta_chofer.php'));
          reqFin.fields['ruta_id'] = fin['ruta_id'].toString();
          reqFin.fields['km_final'] = fin['km_final'].toString();
          reqFin.fields['destino_final'] = fin['destino_final'].toString();

          if (fin['foto_fin'] != null && fin['foto_fin'].toString().isNotEmpty) {
            File foto = File(fin['foto_fin']);
            if (await foto.exists()) {
              reqFin.files.add(await http.MultipartFile.fromPath('foto_fin', foto.path));
            }
          }

          var resFin = await reqFin.send();
          if (resFin.statusCode == 200) {
            await LocalStorageService().marcarSincronizado('ruta_finalizada', fin['id']);
            exitos++;
          } else {
            errores++;
          }
        } catch (e) {
          errores++;
        }
      }

    } catch (e) {
      debugPrint("Error general sincronizando: $e");
    }

    // Refrescar y limpiar datos listos
    await LocalStorageService().limpiarDatosSincronizados();
    await _cargarPendientes();
    
    if (mounted) {
      setState(() => _sincronizando = false);
      if (errores == 0 && exitos > 0) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('✅ Sincronización completa con éxito'), backgroundColor: Colors.green));
      } else if (errores > 0) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('⚠️ Se sincronizaron $exitos registros, pero fallaron $errores. Revisa tu conexión.'), backgroundColor: Colors.orange));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Sincronización Local'),
        backgroundColor: Colors.white,
        foregroundColor: const Color(0xFF4A148C),
        elevation: 1,
      ),
      body: _sincronizando
          ? const Center(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  CircularProgressIndicator(color: Color(0xFF4A148C)),
                  SizedBox(height: 16),
                  Text('Subiendo fotos y datos al servidor...', style: TextStyle(fontSize: 16, color: Colors.grey)),
                  Text('Por favor no cierres la aplicación', style: TextStyle(fontSize: 12, color: Colors.grey)),
                ],
              ),
            )
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text('Datos esperando conexión:', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF4A148C))),
                  const SizedBox(height: 16),
                  
                  if (!_hayDatosPendientes())
                    Container(
                      padding: const EdgeInsets.all(24),
                      decoration: BoxDecoration(color: Colors.green[50], borderRadius: BorderRadius.circular(12), border: Border.all(color: Colors.green[200]!)),
                      child: const Column(
                        children: [
                          Icon(Icons.cloud_done, size: 48, color: Colors.green),
                          SizedBox(height: 12),
                          Text('Todo está sincronizado', style: TextStyle(fontSize: 16, color: Colors.green, fontWeight: FontWeight.bold)),
                          Text('No tienes datos pendientes de subir.', style: TextStyle(color: Colors.green)),
                        ],
                      ),
                    )
                  else ...[
                    if (_rutaInicio != null)
                      _buildTarjetaResumen(Icons.play_arrow, 'Inicio de Ruta', 'Ruta #${_rutaInicio!['ruta_id']}', Colors.blue),
                    
                    if (_gastos.isNotEmpty)
                      _buildTarjetaResumen(Icons.receipt_long, 'Gastos', '${_gastos.length} comprobantes guardados', Colors.orange),

                    if (_cucas.isNotEmpty)
                      _buildTarjetaResumen(Icons.description, 'Facturas / QK', '${_cucas.length} facturas selladas', Colors.teal),
                      
                    if (_rutasFin.isNotEmpty)
                      _buildTarjetaResumen(Icons.flag, 'Fin de Rutas', '${_rutasFin.length} cierres de viaje', Colors.red),

                    const SizedBox(height: 32),
                    ElevatedButton.icon(
                      onPressed: _sincronizarTodo,
                      icon: const Icon(Icons.sync),
                      label: const Text('SINCRONIZAR AHORA'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.green,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 16),
                        textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ]
                ],
              ),
            ),
    );
  }

  Widget _buildTarjetaResumen(IconData icono, String titulo, String subtitulo, Color color) {
    return Card(
      elevation: 1,
      margin: const EdgeInsets.only(bottom: 12),
      child: ListTile(
        leading: CircleAvatar(backgroundColor: color.withOpacity(0.1), child: Icon(icono, color: color)),
        title: Text(titulo, style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text(subtitulo),
        trailing: const Icon(Icons.cloud_upload, color: Colors.grey),
      ),
    );
  }
}
