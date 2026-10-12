import 'dart:convert';
import 'dart:io';
import 'package:path_provider/path_provider.dart';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../models/ruta_model.dart';
import 'package:acarez_driver/pantallas/iniciar_ruta_screen.dart';
import 'package:acarez_driver/pantallas/registrar_parada_screen.dart';
import 'package:acarez_driver/pantallas/sincronizacion_screen.dart';
import '../services/local_storage_service.dart';
import 'package:image_picker/image_picker.dart';
import 'package:geolocator/geolocator.dart';
import 'package:geocoding/geocoding.dart';

class DetalleRutaScreen extends StatefulWidget {
  final RutaProgramada ruta;

  const DetalleRutaScreen({
    Key? key,
    required this.ruta,
  }) : super(key: key);

  @override
  State<DetalleRutaScreen> createState() => _DetalleRutaScreenState();
}

class _DetalleRutaScreenState extends State<DetalleRutaScreen> {
  final Set<int> _destinosCompletados = {};
  final Set<int> _destinosConBorrador = {}; // Memoria de paradas inconclusas
  bool _cargando = false;
  late RutaProgramada _rutaActual;
  
  double _totalGastosRuta = 0.0;
  List<dynamic> _listaGastosRegistrados = [];

  bool _esRutaActiva(String estatus) {
    final e = estatus.toLowerCase().trim();
    return e == 'activa' || e == 'en proceso' || e == 'iniciada' || e == 'en_proceso';
  }

  @override
  void initState() {
    super.initState();
    _rutaActual = widget.ruta;
    _cargarParadasLocales();
    _cargarEstadoDesdeAPI();
    _verificarBorradores(); // Revisar si el chofer dejó algo a medias
  }

  // Busca silenciosamente los borradores pendientes.
  // La pantalla RegistrarParadaScreen guarda actualmente el borrador principal
  // como un archivo JSON en getApplicationDocumentsDirectory().
  // También revisamos SharedPreferences para mantener compatibilidad con
  // borradores creados con la versión anterior de la app.
  Future<void> _verificarBorradores() async {
    try {
      final directory = await getApplicationDocumentsDirectory();
      final prefs = await SharedPreferences.getInstance();
      final Set<int> conBorrador = {};

      for (final destino in _rutaActual.destinos) {
        final String nombreArchivo =
            'borrador_parada_${_rutaActual.id}_${destino.id}.json';
        final File archivoBorrador =
            File('${directory.path}/$nombreArchivo');

        bool existeBorrador = false;

        // 1) Nuevo sistema: archivo JSON.
        if (await archivoBorrador.exists()) {
          try {
            final String contenido = await archivoBorrador.readAsString();

            if (contenido.trim().isNotEmpty) {
              final dynamic datos = jsonDecode(contenido);

              if (datos is Map) {
                final dynamic cucas = datos['cucas'];
                final dynamic gastos = datos['gastos'];

                final bool hayCucas = cucas is List && cucas.isNotEmpty;
                final bool hayGastos = gastos is List && gastos.isNotEmpty;

                existeBorrador = hayCucas || hayGastos;
              }
            }
          } catch (e) {
            debugPrint(
              'Error leyendo borrador de destino ${destino.id}: $e',
            );
          }
        }

        // 2) Compatibilidad con borradores antiguos en SharedPreferences.
        if (!existeBorrador) {
          final String key =
              'borrador_parada_${_rutaActual.id}_${destino.id}';

          if (prefs.containsKey(key)) {
            final String? contenidoAnterior = prefs.getString(key);

            if (contenidoAnterior != null &&
                contenidoAnterior.trim().isNotEmpty) {
              try {
                final dynamic datosAnteriores =
                    jsonDecode(contenidoAnterior);

                if (datosAnteriores is Map) {
                  final dynamic cucas = datosAnteriores['cucas'];
                  final dynamic gastos = datosAnteriores['gastos'];

                  final bool hayCucas =
                      cucas is List && cucas.isNotEmpty;
                  final bool hayGastos =
                      gastos is List && gastos.isNotEmpty;

                  existeBorrador = hayCucas || hayGastos;
                }
              } catch (_) {
                existeBorrador = false;
              }
            }
          }
        }

        if (existeBorrador) {
          conBorrador.add(destino.id);
        }
      }

      if (mounted) {
        setState(() {
          _destinosConBorrador
            ..clear()
            ..addAll(conBorrador);
        });
      }
    } catch (e) {
      debugPrint("Error verificando borradores: $e");
    }
  }

  Future<void> _cargarParadasLocales() async {
    final completadasLocal = await LocalStorageService().obtenerParadasCompletadasLocal(_rutaActual.id);
    if (mounted && completadasLocal.isNotEmpty) {
      setState(() {
        _destinosCompletados.addAll(completadasLocal);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final ruta = _rutaActual;
    final String estatusActual = ruta.estatus;
    final String tituloRutaTexto = ruta.numeroRuta.isNotEmpty ? ruta.numeroRuta : "#${ruta.id}";

    return Scaffold(
      appBar: AppBar(
        title: Text('Ruta $tituloRutaTexto'),
        backgroundColor: Colors.white,
        foregroundColor: const Color(0xFF4A148C),
        elevation: 1,
        actions: [
          IconButton(
            icon: const Icon(Icons.cloud_sync, color: Colors.orange, size: 28),
            tooltip: 'Sincronizar datos pendientes',
            onPressed: () {
              Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (context) => const SincronizacionScreen(baseUrl: 'https://acarez-backend.onrender.com'),
                ),
              ).then((_) {
                _cargarParadasLocales();
                _cargarEstadoDesdeAPI();
                _verificarBorradores();
              });
            },
          ),
          IconButton(
            icon: _cargando 
                ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.refresh),
            onPressed: () async {
              await _cargarParadasLocales();
              await _cargarEstadoDesdeAPI();
              await _verificarBorradores();
            },
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Card(
              elevation: 2,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          'Ruta $tituloRutaTexto',
                          style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Color(0xFF4A148C)),
                        ),
                        _buildStatusChip(estatusActual),
                      ],
                    ),
                    const SizedBox(height: 12),
                    
                    if (ruta.numeroRuta.isNotEmpty)
                      _buildInfoRow('🔢 Número de Ruta', ruta.numeroRuta, destacado: true),

                    _buildInfoRow('👤 Chofer', ruta.chofer),
                    if (ruta.auxiliar != null && ruta.auxiliar!.isNotEmpty)
                      _buildInfoRow('🤝 Auxiliar', ruta.auxiliar!),
                    _buildInfoRow('🚛 Placas', ruta.placas),
                    _buildInfoRow('🔢 No. Económico', ruta.noEconomico),
                    _buildInfoRow('📍 Origen', ruta.origen),
                    _buildInfoRow('📅 Fecha Inicio', _formatDate(ruta.fechaInicio)),
                    _buildInfoRow('📊 KM Inicial', '${ruta.kmInicial.toStringAsFixed(2)} km'),
                    _buildInfoRow('📌 Paradas', '${ruta.numeroParadas} destinos'),
                    
                    _buildInfoRow('💰 Total Gastos', '\$${_totalGastosRuta.toStringAsFixed(2)}', destacado: true),

                    if (ruta.estatus.toLowerCase() == 'completada' && ruta.kmFinal != null)
                      _buildInfoRow('🏁 KM Final', '${ruta.kmFinal!.toStringAsFixed(2)} km'),
                    if (ruta.estatus.toLowerCase() == 'completada' && ruta.kmTotal != null)
                      _buildInfoRow('📏 KM Total', '${ruta.kmTotal!.toStringAsFixed(2)} km'),
                    if (ruta.destinoFinal != null && ruta.destinoFinal!.isNotEmpty)
                      _buildInfoRow('🏁 Destino Final', ruta.destinoFinal!),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 16),

            Row(
              children: [
                const Text('📍 Destinos de la Ruta', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF4A148C))),
                const Spacer(),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                  decoration: BoxDecoration(color: const Color(0xFF4A148C).withOpacity(0.1), borderRadius: BorderRadius.circular(20)),
                  child: Text('${ruta.destinos.length} destinos', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF4A148C))),
                ),
              ],
            ),
            const SizedBox(height: 8),

            if (ruta.destinos.isEmpty)
              const Padding(
                padding: EdgeInsets.all(20.0),
                child: Center(child: Text('No hay destinos registrados para esta ruta')),
              )
            else
              ...ruta.destinos.map((destino) => _buildDestinoCard(destino, context, estatusActual)),

            const SizedBox(height: 16),

            if (_listaGastosRegistrados.isNotEmpty) ...[
              const Text('💳 Gastos Desglosados', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF4A148C))),
              const SizedBox(height: 8),
              Card(
                elevation: 1,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                child: ListView.separated(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: _listaGastosRegistrados.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, index) {
                    final g = _listaGastosRegistrados[index];
                    final concepto = g['concepto'] ?? g['tipo'] ?? 'Gasto';
                    final monto = double.tryParse(g['monto']?.toString() ?? '0') ?? 0.0;
                    final fecha = g['fecha'] ?? '';

                    return ListTile(
                      dense: true,
                      leading: const Icon(Icons.receipt_long, color: Color(0xFF4A148C)),
                      title: Text(concepto, style: const TextStyle(fontWeight: FontWeight.w600)),
                      subtitle: fecha.isNotEmpty ? Text(fecha, style: const TextStyle(fontSize: 11)) : null,
                      trailing: Text('\$${monto.toStringAsFixed(2)}', style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.green, fontSize: 14)),
                    );
                  },
                ),
              ),
              const SizedBox(height: 16),
            ],

            if (estatusActual.toLowerCase() == 'programada')
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () => _iniciarRuta(context),
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.green, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(vertical: 16)),
                  child: const Text('🚀 Iniciar Ruta'),
                ),
              ),

            if (_esRutaActiva(estatusActual))
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () => _finalizarRuta(context),
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.blue, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(vertical: 16)),
                  child: const Text('🏁 Finalizar Ruta'),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildDestinoCard(
    Destino destino,
    BuildContext context,
    String estatusActual,
  ) {
    // Si existe un borrador, la parada se muestra como "en pausa"
    // aunque el servidor/local la hubiera marcado previamente como completada.
    final bool tieneBorrador = _destinosConBorrador.contains(destino.id);

    final bool isCompletado = (_destinosCompletados.contains(destino.id) ||
        destino.completado ||
        destino.estatus.toLowerCase() == 'completado' ||
        destino.estatus.toLowerCase() == 'completada') &&
        !tieneBorrador;

    // Estados visuales:
    // Verde = parada completada.
    // Rojo = captura en pausa (borrador).
    // Blanco = parada pendiente.
    final Color borderColor = isCompletado
        ? Colors.green
        : (tieneBorrador ? Colors.red : Colors.grey[300]!);

    final Color bgColor = isCompletado
        ? Colors.green[50]!
        : (tieneBorrador ? Colors.red[50]! : Colors.white);

    final Color iconBgColor = isCompletado
        ? Colors.green.withOpacity(0.20)
        : (tieneBorrador
            ? Colors.red.withOpacity(0.15)
            : const Color(0xFF4A148C).withOpacity(0.10));

    return Card(
      margin: const EdgeInsets.only(bottom: 8.0),
      elevation: isCompletado ? 0 : 2,
      color: bgColor,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(
          color: borderColor,
          width: isCompletado || tieneBorrador ? 2.5 : 1,
        ),
      ),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: iconBgColor,
          child: isCompletado
              ? const Icon(
                  Icons.check,
                  color: Colors.green,
                )
              : (tieneBorrador
                  ? const Icon(
                      Icons.pause_circle_filled,
                      color: Colors.red,
                      size: 24,
                    )
                  : Text(
                      '${destino.orden}',
                      style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF4A148C),
                      ),
                    )),
        ),
        title: Text(
          destino.razonSocial,
          style: TextStyle(
            fontWeight: FontWeight.bold,
            fontSize: 15,
            decoration:
                isCompletado ? TextDecoration.lineThrough : null,
            color: isCompletado
                ? Colors.grey[600]
                : (tieneBorrador ? Colors.red[900] : Colors.black87),
          ),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (destino.sucursal.isNotEmpty)
              Text(
                '🏢 ${destino.sucursal}',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight:
                      tieneBorrador ? FontWeight.w600 : FontWeight.normal,
                  color: isCompletado
                      ? Colors.grey[600]
                      : (tieneBorrador ? Colors.red[800] : null),
                ),
              ),
            if (destino.direccion.isNotEmpty)
              Text(
                '📍 ${destino.direccion}',
                style: TextStyle(
                  fontSize: 13,
                  color: isCompletado
                      ? Colors.grey[600]
                      : (tieneBorrador ? Colors.red[700] : null),
                ),
              ),
            if (isCompletado)
              Text(
                '✅ Completado',
                style: TextStyle(
                  color: Colors.green[700],
                  fontWeight: FontWeight.w600,
                  fontSize: 12,
                ),
              ),
            if (tieneBorrador)
              const Padding(
                padding: EdgeInsets.only(top: 2),
                child: Text(
                  '⏸ Captura en pausa (Borrador)',
                  style: TextStyle(
                    color: Colors.red,
                    fontWeight: FontWeight.bold,
                    fontSize: 12,
                  ),
                ),
              ),
          ],
        ),
        isThreeLine: destino.sucursal.isNotEmpty &&
            destino.direccion.isNotEmpty,
        trailing: isCompletado
            ? const Icon(
                Icons.check_circle,
                color: Colors.green,
                size: 24,
              )
            : (tieneBorrador
                ? const Icon(
                    Icons.pause_circle_filled,
                    color: Colors.red,
                    size: 24,
                  )
                : Icon(
                    Icons.arrow_forward_ios,
                    size: 16,
                    color: Colors.grey[400],
                  )),
        onTap: () {

          // Si está completado y NO tiene borrador, mostramos advertencia para reabrir y reescribir
          if (isCompletado && !tieneBorrador) {
            showDialog(
              context: context,
              builder: (context) => AlertDialog(
                title: const Text('⚠️ Parada ya completada', style: TextStyle(color: Colors.red)),
                content: const Text('Si vuelves a registrar esta parada, se borrarán todos los gastos y Qukas anteriores. Tendrás que capturar TODO de nuevo.\n\n¿Estás seguro de que deseas reabrirla?'),
                actions: [
                  TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('Cancelar', style: TextStyle(color: Colors.grey)),
                  ),
                  ElevatedButton(
                    style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white),
                    onPressed: () {
                      Navigator.pop(context); 
                      setState(() {
                        _destinosCompletados.remove(destino.id);
                      });
                      _registrarParada(context, destino); 
                    },
                    child: const Text('Reescribir Todo'),
                  ),
                ],
              ),
            );
            return;
          }

          if (_esRutaActiva(estatusActual)) {
            _registrarParada(context, destino);
          } else if (estatusActual.toLowerCase() == 'programada') {
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('⏳ La ruta debe estar activa (Estado: $estatusActual)'), backgroundColor: Colors.orange));
          } else {
            ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('❌ Esta ruta ya está completada o cancelada'), backgroundColor: Colors.red));
          }
        },
      ),
    );
  }

  Widget _buildStatusChip(String estatus) {
    Color color;
    String texto;
    final estatusLwr = estatus.toLowerCase().trim();

    if (estatusLwr == 'programada') {
      color = Colors.orange; texto = 'PROGRAMADA';
    } else if (_esRutaActiva(estatusLwr)) {
      color = Colors.green; texto = 'EN CURSO';
    } else if (estatusLwr == 'completada') {
      color = Colors.blue; texto = 'COMPLETADA';
    } else if (estatusLwr == 'cancelada') {
      color = Colors.red; texto = 'CANCELADA';
    } else {
      color = Colors.grey; texto = estatus.toUpperCase();
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(20)),
      child: Text(texto, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }

  Widget _buildInfoRow(String label, String value, {bool destacado = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(width: 110, child: Text(label, style: TextStyle(fontWeight: destacado ? FontWeight.bold : FontWeight.w500, color: destacado ? const Color(0xFF4A148C) : Colors.grey, fontSize: 13))),
          Expanded(child: Text(value, style: TextStyle(fontWeight: destacado ? FontWeight.bold : FontWeight.w500, color: destacado ? Colors.green[700] : Colors.black87, fontSize: destacado ? 15 : 13))),
        ],
      ),
    );
  }

  String _formatDate(DateTime date) {
    return '${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')}/${date.year} ${date.hour.toString().padLeft(2, '0')}:${date.minute.toString().padLeft(2, '0')}';
  }

  void _iniciarRuta(BuildContext context) async {
    final resultado = await Navigator.push<bool>(
      context,
      MaterialPageRoute(
        builder: (context) => IniciarRutaScreen(ruta: _rutaActual, baseUrl: 'https://acarez-backend.onrender.com', listaPlacas: const [], listaNoEconomico: const []),
      ),
    );

    if (resultado == true && mounted) {
      await LocalStorageService().guardarRutaActiva(_rutaActual.id);
      setState(() => _rutaActual = _rutaActual.copyWith(estatus: 'en_proceso'));
      await _cargarParadasLocales();
      await _cargarEstadoDesdeAPI();
    }
  }

  void _finalizarRuta(BuildContext context) async {
    final TextEditingController kmFinalController = TextEditingController();
    final TextEditingController destinoFinalController = TextEditingController();
    
    String? fotoRutaPath;
    bool obteniendoGps = true;
    bool procesandoFoto = false;

    Future<void> obtenerUbicacionGPS(StateSetter setDialogState) async {
      try {
        bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
        if (!serviceEnabled) { setDialogState(() => obteniendoGps = false); return; }

        LocationPermission permission = await Geolocator.checkPermission();
        if (permission == LocationPermission.denied) {
          permission = await Geolocator.requestPermission();
          if (permission == LocationPermission.denied) { setDialogState(() => obteniendoGps = false); return; }
        }
        if (permission == LocationPermission.deniedForever) { setDialogState(() => obteniendoGps = false); return; }

        Position position = await Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.high);
        String direccionTexto = 'Lat: ${position.latitude.toStringAsFixed(4)}, Lon: ${position.longitude.toStringAsFixed(4)}';
        try {
          final marcas = await placemarkFromCoordinates(position.latitude, position.longitude);
          if (marcas.isNotEmpty) direccionTexto = '${marcas[0].street ?? ''}, ${marcas[0].locality ?? ''}, ${marcas[0].country ?? ''}';
        } catch (_) {}

        setDialogState(() { destinoFinalController.text = direccionTexto; obteniendoGps = false; });
      } catch (e) {
        setDialogState(() => obteniendoGps = false);
      }
    }

    final bool? confirmar = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) {
          if (destinoFinalController.text.isEmpty && obteniendoGps) obtenerUbicacionGPS(setDialogState);

          return Dialog(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 450),
              child: Padding(
                padding: const EdgeInsets.all(20.0),
                child: SingleChildScrollView(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        '🏁 Finalizar Ruta',
                        style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF4A148C)),
                      ),
                      const SizedBox(height: 12),
                      Text('Ruta #${_rutaActual.id} - Cierre de viaje:'),
                      const SizedBox(height: 16),
                      TextField(
                        controller: kmFinalController,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: const InputDecoration(
                          labelText: 'Kilometraje Final (KM)*', 
                          border: OutlineInputBorder(), 
                          prefixIcon: Icon(Icons.speed),
                        ),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: destinoFinalController,
                        decoration: InputDecoration(
                          labelText: 'Destino Final (GPS)', 
                          border: const OutlineInputBorder(), 
                          prefixIcon: const Icon(Icons.location_on),
                          suffixIcon: obteniendoGpsWidget(obteniendoGps, setDialogState, obtenerUbicacionGPS),
                        ),
                      ),
                      const SizedBox(height: 16),
                      
                      SizedBox(
                        width: double.infinity,
                        child: procesandoFoto
                            ? Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(color: Colors.grey[100], borderRadius: BorderRadius.circular(8)),
                                child: const Row(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)),
                                    SizedBox(width: 12),
                                    Text('Procesando fotografía...', style: TextStyle(fontSize: 13, color: Colors.grey)),
                                  ],
                                ),
                              )
                            : (fotoRutaPath == null)
                                ? ElevatedButton.icon(
                                    onPressed: () async {
                                      setDialogState(() => procesandoFoto = true);
                                      try {
                                        final picker = ImagePicker();
                                        final pickedFile = await picker.pickImage(source: ImageSource.camera, imageQuality: 70);
                                        if (pickedFile != null) {
                                          setDialogState(() {
                                            fotoRutaPath = pickedFile.path;
                                            procesandoFoto = false;
                                          });
                                        } else {
                                          setDialogState(() => procesandoFoto = false);
                                        }
                                      } catch (e) {
                                          setDialogState(() => procesandoFoto = false);
                                      }
                                    },
                                    icon: const Icon(Icons.camera_alt),
                                    label: const Text('Tomar Foto de Kilometraje'),
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: Colors.blue,
                                      foregroundColor: Colors.white,
                                      padding: const EdgeInsets.symmetric(vertical: 12),
                                    ),
                                  )
                                : Column(
                                    children: [
                                      ClipRRect(
                                        borderRadius: BorderRadius.circular(10),
                                        child: Image.file(
                                          File(fotoRutaPath!),
                                          height: 160,
                                          width: double.infinity,
                                          fit: BoxFit.cover,
                                        ),
                                      ),
                                      const SizedBox(height: 8),
                                      const Text('✅ Foto tomada correctamente', style: TextStyle(color: Colors.green, fontSize: 13)),
                                      const SizedBox(height: 8),
                                      OutlinedButton.icon(
                                        onPressed: () async {
                                          setDialogState(() => procesandoFoto = true);
                                          try {
                                            final picker = ImagePicker();
                                            final pickedFile = await picker.pickImage(source: ImageSource.camera, imageQuality: 70);
                                            if (pickedFile != null) {
                                              setDialogState(() {
                                                fotoRutaPath = pickedFile.path;
                                                procesandoFoto = false;
                                              });
                                            } else {
                                              setDialogState(() => procesandoFoto = false);
                                            }
                                          } catch (e) {
                                            setDialogState(() => procesandoFoto = false);
                                          }
                                        },
                                        icon: const Icon(Icons.refresh),
                                        label: const Text('Cambiar Foto'),
                                        style: OutlinedButton.styleFrom(
                                          foregroundColor: Colors.blue,
                                          side: const BorderSide(color: Colors.blue),
                                        ),
                                      ),
                                    ],
                                  ),
                      ),
                      
                      const SizedBox(height: 20),
                      
                      Row(
                        mainAxisAlignment: MainAxisAlignment.end,
                        children: [
                          TextButton(
                            onPressed: () => Navigator.pop(context, false), 
                            child: const Text('Cancelar'),
                          ),
                          const SizedBox(width: 8),
                          ElevatedButton(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: Colors.blue, 
                              foregroundColor: Colors.white,
                            ),
                            onPressed: procesandoFoto ? null : () {
                              if (kmFinalController.text.trim().isEmpty) {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  const SnackBar(content: Text('⚠️ Debes ingresar el kilometraje final'))
                                );
                                return;
                              }
                              Navigator.pop(context, true);
                            },
                            child: const Text('Finalizar Ruta'),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );

    if (confirmar != true) return;

    setState(() => _cargando = true);

    try {
      final url = Uri.parse('https://acarez-backend.onrender.com/api/finalizar_ruta_chofer.php');
      var request = http.MultipartRequest('POST', url);
      request.fields['ruta_id'] = _rutaActual.id.toString();
      request.fields['km_final'] = kmFinalController.text.trim();
      request.fields['destino_final'] = destinoFinalController.text.trim();

      if (fotoRutaPath != null && fotoRutaPath!.isNotEmpty) {
        request.files.add(await http.MultipartFile.fromPath('foto_fin', fotoRutaPath!));
      }

      final streamedResponse = await request.send().timeout(const Duration(seconds: 15));
      final response = await http.Response.fromStream(streamedResponse);

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          await LocalStorageService().borrarRutaActiva();
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(data['mensaje'] ?? '🏁 Ruta finalizada correctamente'), backgroundColor: Colors.green));
            Navigator.pop(context, true);
          }
        } else {
          throw data['mensaje'] ?? 'Error desconocido en el servidor';
        }
      } else {
        throw 'Error HTTP: ${response.statusCode}';
      }
    } catch (e) {
      try {
        final double kmFinal = double.tryParse(kmFinalController.text.trim()) ?? 0.0;
        final mapaFin = {
          'ruta_id': _rutaActual.id,
          'km_final': kmFinal,
          'foto_fin': fotoRutaPath ?? '',
          'destino_final': destinoFinalController.text.trim(),
          'fecha_fin': DateTime.now().toIso8601String(),
          'sincronizado': 0,
        };

        await LocalStorageService().guardarRutaFinalizada(mapaFin);
        await LocalStorageService().borrarRutaActiva();

        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('⚠️ Sin conexión. Ruta finalizada localmente. Súbela desde el botón de la nube.'),
              backgroundColor: Colors.orange,
              duration: Duration(seconds: 4),
            ),
          );
          Navigator.pop(context, true);
        }
      } catch (localError) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('❌ Error al guardar localmente: $localError'), backgroundColor: Colors.red),
          );
        }
      }
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }

  Widget obteniendoGpsWidget(bool obteniendoGps, StateSetter setDialogState, Future<void> Function(StateSetter) obtenerUbicacionGPS) {
    if (obteniendoGps) return const SizedBox(width: 20, height: 20, child: Padding(padding: EdgeInsets.all(10), child: CircularProgressIndicator(strokeWidth: 2)));
    return IconButton(icon: const Icon(Icons.my_location), onPressed: () { setDialogState(() => obteniendoGps = true); obtenerUbicacionGPS(setDialogState); }, tooltip: 'Reintentar GPS');
  }

  void _registrarParada(BuildContext context, Destino destino) async {
    final bool? resultado = await Navigator.push<bool>(
      context,
      MaterialPageRoute(builder: (context) => RegistrarParadaScreen(ruta: _rutaActual, destino: destino, baseUrl: 'https://acarez-backend.onrender.com')),
    );
    
    // Actualizamos visualmente el estado del destino: rojo si quedó en borrador,
    // verde si ya fue completado y enviado.
    await _verificarBorradores(); 

    if (resultado == true && mounted) {
      setState(() => _destinosCompletados.add(destino.id));
      await _cargarParadasLocales();
      await _cargarEstadoDesdeAPI();
    }
  }

  Future<void> _cargarEstadoDesdeAPI() async {
    if (_cargando) return;
    setState(() => _cargando = true);

    try {
      final urlRuta = Uri.parse('https://acarez-backend.onrender.com/panel_unificado.php?accion=get_ruta_data&ruta_id=${_rutaActual.id}');
      final responseRuta = await http.get(urlRuta).timeout(const Duration(seconds: 10));

      if (responseRuta.statusCode == 200) {
        final dataRuta = json.decode(responseRuta.body);
        
        if (dataRuta['success'] == true) {
          final mapaRuta = dataRuta['ruta'] ?? {};
          final rawParadas = dataRuta['paradas'] ?? [];
          final double totalGastosCalculado = double.tryParse(dataRuta['total_gastos']?.toString() ?? '0') ?? 0.0;
          final List<dynamic> gastosExtraidos = dataRuta['gastos'] ?? [];

          RutaProgramada rutaActualizada;
          try {
            rutaActualizada = RutaProgramada.fromJson(mapaRuta);
          } catch (_) {
            rutaActualizada = _rutaActual.copyWith(
              estatus: mapaRuta['estatus'] ?? _rutaActual.estatus,
              auxiliar: mapaRuta['auxiliar'] ?? _rutaActual.auxiliar,
              origen: mapaRuta['origen'] ?? _rutaActual.origen,
              kmInicial: mapaRuta['km_inicial'] != null ? double.tryParse(mapaRuta['km_inicial'].toString()) ?? _rutaActual.kmInicial : _rutaActual.kmInicial,
            );
          }

          List<Destino> listaDestinos = [];
          Set<int> idsCompletadosServidor = {};

          for (var d in rawParadas) {
            final destinoParsed = Destino.fromJson(d);
            listaDestinos.add(destinoParsed);
            if (destinoParsed.completado || destinoParsed.estatus == 'completado' || destinoParsed.estatus == 'completada') {
              idsCompletadosServidor.add(destinoParsed.id);
            }
          }

          rutaActualizada = rutaActualizada.copyWith(destinos: listaDestinos);
          final completadasLocal = await LocalStorageService().obtenerParadasCompletadasLocal(_rutaActual.id);

          if (mounted) {
            setState(() {
              _rutaActual = rutaActualizada;
              _totalGastosRuta = totalGastosCalculado;
              _listaGastosRegistrados = gastosExtraidos;
              _destinosCompletados.addAll(idsCompletadosServidor);
              _destinosCompletados.addAll(completadasLocal);
            });
          }
        }
      }
    } catch (e) {
      print('❌ Excepción al cargar estado desde API: $e');
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }
}
